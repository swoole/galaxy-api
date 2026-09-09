<?php

namespace App\Middleware;

use App\Model\Project;
use App\Model\ProjectAuditLog;
use App\Model\ProjectRetentionPolicy;
use App\Services\PermissionService;
use App\Support\Functions;
use FastRoute\Dispatcher;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class ProjectAuditMiddleware implements MiddlewareInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('app-audit');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $projectId = (int) Functions::getContextValue('project_id', false, 0);
        if ($projectId < 1 || in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $startedAt = microtime(true);
        $error = null;
        $response = null;
        try {
            return $response = $handler->handle($request);
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            try {
                $this->write($request, $response, $error, $startedAt, $projectId);
            } catch (Throwable $auditError) {
                // Audit storage must never turn an otherwise valid product operation into a failure.
                $this->logger->error('项目审计日志写入失败：' . $auditError->getMessage());
            }
        }
    }

    private function write(
        ServerRequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $error,
        float $startedAt,
        int $projectId
    ): void {
        /** @var Dispatched|null $dispatched */
        $dispatched = $request->getAttribute(Dispatched::class);
        if ($dispatched === null || $dispatched->status !== Dispatcher::FOUND) {
            return;
        }
        // A successful project deletion removes its own governance data.
        // Do not recreate an orphan audit row and retention policy from this
        // middleware's post-response phase.
        $orgId = (int) Functions::getContextValue('org_id');
        $groupId = (int) Functions::getContextValue('group_id');
        if (! Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)->exists()) {
            return;
        }
        $callback = PermissionService::parseCallback($dispatched->handler->callback) ?? 'unknown';
        $parts = explode('@', $callback, 2);
        $server = $request->getServerParams();
        $metadata = array_merge($request->getQueryParams(), (array) ($request->getParsedBody() ?? []));
        unset($metadata['org_id'], $metadata['group_id'], $metadata['project_id']);
        // Pipeline Secret uses the intentionally generic API field `value`.
        // Key-name based sanitizing alone cannot recognize it as credential
        // material, so apply an action-specific policy before persistence.
        if ($callback === 'App\\Controller\\PipelineController@putSecret' && array_key_exists('value', $metadata)) {
            $metadata['value'] = '[redacted]';
        }
        if (in_array($callback, [
            'App\\Controller\\ProjectConfigurationController@create',
            'App\\Controller\\ProjectConfigurationController@update',
        ], true) && ($metadata['kind'] ?? '') === 'secret' && array_key_exists('value', $metadata)) {
            $metadata['value'] = '[redacted]';
        }
        ProjectAuditLog::create([
            'org_id' => $orgId,
            'group_id' => $groupId,
            'project_id' => $projectId,
            'uid' => (int) Functions::getLoginUser()->getId(),
            'method' => strtoupper($request->getMethod()),
            'path' => mb_substr($request->getUri()->getPath(), 0, 255),
            'controller' => mb_substr($parts[0], 0, 255),
            'action' => mb_substr($parts[1] ?? $parts[0], 0, 128),
            'status' => $error === null ? ProjectAuditLog::STATUS_SUCCEEDED : ProjectAuditLog::STATUS_FAILED,
            'http_status' => $response?->getStatusCode() ?? $this->errorStatus($error),
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'ip' => mb_substr((string) ($server['remote_addr'] ?? ''), 0, 64),
            'user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 512),
            'metadata' => $this->sanitize($metadata),
            'error' => $error === null ? null : mb_substr($error->getMessage(), 0, 4000),
            'created_at' => time(),
        ]);
        $defaults = (array) config('project-governance.defaults', []);
        ProjectRetentionPolicy::firstOrCreate([
            'org_id' => $orgId,
            'group_id' => $groupId,
            'project_id' => $projectId,
        ], [
            'enabled' => 1,
            'metric_days' => (int) ($defaults['metric_days'] ?? 30),
            'event_days' => (int) ($defaults['event_days'] ?? 90),
            'resolved_alert_days' => (int) ($defaults['resolved_alert_days'] ?? 180),
            'build_log_days' => (int) ($defaults['build_log_days'] ?? 90),
            'audit_days' => (int) ($defaults['audit_days'] ?? 365),
            'last_purged_at' => 0,
            'creator' => (int) Functions::getLoginUser()->getId(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return '[truncated]';
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                if (preg_match('/password|passwd|token|secret|credential|authorization|certificate|private.?key|access.?key/i', (string) $key)) {
                    $result[$key] = '[redacted]';
                } else {
                    $result[$key] = $this->sanitize($item, $depth + 1);
                }
            }
            return $result;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 1000);
        }
        return is_scalar($value) || $value === null ? $value : (string) $value;
    }

    private function errorStatus(?Throwable $error): int
    {
        if ($error === null) {
            return 200;
        }
        $code = (int) $error->getCode();
        return $code >= 400 && $code <= 599 ? $code : 500;
    }
}
