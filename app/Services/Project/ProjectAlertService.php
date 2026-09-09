<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectAlert;
use App\Model\ProjectAlertRule;
use App\Model\ProjectMember;
use App\Model\ProjectRuntime;
use App\Model\User;
use App\Services\Notify\Channel\Email;
use Throwable;

class ProjectAlertService
{
    public function __construct(
        private Email $email,
        private ProjectRuntimeEventService $events,
        private ProjectMutationLock $projectMutationLock
    ) {}

    public function profile(
        int $orgId,
        int $groupId,
        int $projectId,
        ?int $runtimeId = null
    ): array
    {
        if ($runtimeId !== null && ! ProjectRuntime::where('id', $runtimeId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->exists()) {
            throw new AppException(404, '所选运行实例不存在或不属于当前项目');
        }
        if ($runtimeId !== null) {
            try {
                // 实例详情是用户显式查看操作，立即读取一次编排器事件，
                // 避免只能等待后台采集周期或错过短期 Kubernetes Event。
                $this->events->refreshRuntime($orgId, $groupId, $projectId, $runtimeId);
            } catch (Throwable $e) {
                logger()->warning('刷新实例运行事件失败', [
                    'runtime_id' => $runtimeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $rule = ProjectAlertRule::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->first();
        if ($rule === null) {
            $rule = new ProjectAlertRule([
                'org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId,
                'enabled' => 0, 'recipients' => [], 'failure_threshold' => 2,
                'cooldown_seconds' => 1800, 'notify_recovery' => 1,
                'slo_availability_target' => 99.9, 'slo_error_rate_max' => 1.0,
                'slo_p95_ms_max' => 500,
                'created_at' => 0, 'updated_at' => 0,
            ]);
        }
        $alerts = ProjectAlert::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId);
        if ($runtimeId !== null) {
            $alerts->where('runtime_id', $runtimeId);
        }
        return [
            'rule' => $rule,
            'alerts' => $alerts->orderBy('last_seen_at', 'desc')->limit(100)->get(),
            'events' => $this->events->list($orgId, $groupId, $projectId, 100, $runtimeId),
        ];
    }

    public function save(int $uid, int $orgId, int $groupId, int $projectId, array $input): ProjectAlertRule
    {
        $now = time();
        $rule = ProjectAlertRule::firstOrNew(['org_id' => $orgId, 'group_id' => $groupId, 'project_id' => $projectId]);
        if (! $rule->exists) {
            $rule->creator = $uid;
            $rule->created_at = $now;
        }
        $rule->enabled = (bool) ($input['enabled'] ?? false);
        $rule->recipients = array_values(array_unique(array_filter(array_map('trim', $input['recipients'] ?? []))));
        $rule->failure_threshold = max(1, min(20, (int) ($input['failure_threshold'] ?? 2)));
        $rule->cooldown_seconds = max(300, min(86400, (int) ($input['cooldown_seconds'] ?? 1800)));
        $rule->notify_recovery = (bool) ($input['notify_recovery'] ?? true);
        $rule->slo_availability_target = max(90.0, min(100.0,
            (float) ($input['slo_availability_target'] ?? 99.9)));
        $rule->slo_error_rate_max = max(0.0, min(100.0,
            (float) ($input['slo_error_rate_max'] ?? 1.0)));
        $rule->slo_p95_ms_max = max(1, min(600000, (int) ($input['slo_p95_ms_max'] ?? 500)));
        $rule->updated_at = $now;
        $rule->save();
        return $rule;
    }

    public function evaluate(array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            try {
                $this->projectMutationLock->synchronized(
                    (int) $snapshot['org_id'],
                    (int) $snapshot['project_id'],
                    fn () => $this->evaluateSnapshot($snapshot),
                    0,
                    120
                );
            } catch (AppException $e) {
                if ($e->getCode() !== 409) {
                    throw $e;
                }
            }
        }
    }

    private function evaluateSnapshot(array $snapshot): void
    {
        if (! Project::where('id', (int) $snapshot['project_id'])->where('org_id', (int) $snapshot['org_id'])
            ->where('group_id', (int) $snapshot['group_id'])->exists()
            || ! ProjectRuntime::where('id', (int) $snapshot['runtime_id'])->exists()) {
            return;
        }
        $rule = ProjectAlertRule::where('org_id', (int) $snapshot['org_id'])
            ->where('group_id', (int) $snapshot['group_id'])->where('project_id', (int) $snapshot['project_id'])
            ->where('enabled', 1)->first();
        if ($rule === null) {
            return;
        }
        $fingerprint = hash('sha256', 'runtime-availability:' . $snapshot['runtime_id']);
        $healthy = ($snapshot['health'] ?? '') === 'healthy'
            && ! in_array($snapshot['update_state'] ?? '', ['paused', 'rollback_paused'], true);
        if ($healthy) {
            $this->resolve($rule, $fingerprint, $snapshot);
        } else {
            $this->open($rule, $fingerprint, $snapshot);
        }
    }

    private function open(ProjectAlertRule $rule, string $fingerprint, array $snapshot): void
    {
        $now = time();
        $message = $snapshot['error'] ?: ($snapshot['update_message'] ?: sprintf(
            '期望副本 %d，运行副本 %d，失败任务 %d',
            $snapshot['desired_tasks'], $snapshot['running_tasks'], $snapshot['failed_tasks']
        ));
        $alert = ProjectAlert::where('fingerprint', $fingerprint)->first();
        if ($alert === null || $alert->status === ProjectAlert::STATUS_RESOLVED) {
            $alert = ProjectAlert::updateOrCreate(['fingerprint' => $fingerprint], [
                'org_id' => (int) $snapshot['org_id'], 'group_id' => (int) $snapshot['group_id'],
                'project_id' => (int) $snapshot['project_id'], 'runtime_id' => (int) $snapshot['runtime_id'],
                'rule_id' => (int) $rule->id, 'severity' => 'critical', 'status' => ProjectAlert::STATUS_OPEN,
                'title' => '运行实例异常：' . $snapshot['name'], 'message' => $message,
                'context' => $snapshot, 'occurrences' => 1, 'first_seen_at' => $now,
                'last_seen_at' => $now, 'notified_at' => 0, 'resolved_at' => 0, 'notification_error' => '',
            ]);
        } else {
            $alert->occurrences = (int) $alert->occurrences + 1;
            $alert->last_seen_at = $now;
            $alert->message = $message;
            $alert->context = $snapshot;
            $alert->save();
        }
        if ((int) $alert->occurrences >= (int) $rule->failure_threshold
            && ($alert->notified_at === 0 || $now - (int) $alert->notified_at >= (int) $rule->cooldown_seconds)) {
            $this->notify($rule, $alert, false);
        }
    }

    private function resolve(ProjectAlertRule $rule, string $fingerprint, array $snapshot): void
    {
        $alert = ProjectAlert::where('fingerprint', $fingerprint)->where('status', ProjectAlert::STATUS_OPEN)->first();
        if ($alert === null) {
            return;
        }
        $alert->status = ProjectAlert::STATUS_RESOLVED;
        $alert->resolved_at = time();
        $alert->last_seen_at = time();
        $alert->context = $snapshot;
        $alert->save();
        if ($rule->notify_recovery && (int) $alert->notified_at > 0 && (string) $alert->notification_error === '') {
            $this->notify($rule, $alert, true);
        }
    }

    private function notify(ProjectAlertRule $rule, ProjectAlert $alert, bool $recovery): void
    {
        try {
            $recipients = $this->recipients($rule);
            if ($recipients === []) {
                throw new \RuntimeException('没有可用的告警收件人');
            }
            $projectTitle = (string) (Project::where('id', $alert->project_id)->value('title') ?: '#' . $alert->project_id);
            $this->email->sendEmail(array_fill_keys($recipients, ''),
                ($recovery ? '[已恢复] ' : '[告警] ') . $alert->title,
                'project_runtime_alert', ['alert' => $alert->toArray(), 'project_title' => $projectTitle, 'recovery' => $recovery]
            );
            $alert->notified_at = time();
            $alert->notification_error = '';
        } catch (Throwable $e) {
            // Record the attempt as well as the error so an SMTP outage follows
            // the configured cooldown instead of retrying every monitor cycle.
            $alert->notified_at = time();
            $alert->notification_error = mb_substr($e->getMessage(), 0, 2000);
        }
        $alert->save();
    }

    private function recipients(ProjectAlertRule $rule): array
    {
        $configured = array_values(array_filter((array) $rule->recipients,
            static fn ($email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
        if ($configured !== []) {
            return $configured;
        }
        $uids = ProjectMember::where('org_id', $rule->org_id)->where('group_id', $rule->group_id)
            ->where('project_id', $rule->project_id)->pluck('uid')->all();
        return User::whereIn('id', $uids)->where('email', '<>', '')->pluck('email')->unique()->values()->all();
    }
}
