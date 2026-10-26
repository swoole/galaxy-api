<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Services\Gateway\WafAutoBanService;
use App\Services\Gateway\WafBrowserChallenge;
use App\Services\Gateway\WafBrowserToken;
use App\Services\Gateway\WafChallengePage;
use App\Services\Gateway\WafNetworkPolicy;
use App\Services\Gateway\WafRuntimeConfigRegistry;
use Throwable;

/** Dedicated machine endpoint: never use API JSON success envelopes for auth_request statuses. */
final class WafControlController extends AbstractController
{
    public function __construct(private WafRuntimeConfigRegistry $configs, private WafAutoBanService $bans,
        private WafBrowserChallenge $browser, private WafChallengePage $pages, private WafNetworkPolicy $network) {}

    public function check()
    {
        try {
            $snapshot = $this->configs->authenticate((string) $this->request->header('X-Galaxy-Waf-Token', ''));
            $decision = $this->bans->decision($snapshot, (string) $this->request->header('X-Galaxy-Waf-Client', ''));
            $data = ['country' => '', 'source' => '', 'data_status' => 'ok'];
            if ($this->request->header('X-Galaxy-Waf-Probe', '') === '1') {
                $this->browser->assertAvailable($snapshot);
                if (! empty($snapshot->settings['browser']['enabled'])) $this->pages->assertAvailable();
                $this->network->assertConfigured($snapshot->org_id, $snapshot->settings);
                $decision = ['status' => 204, 'action' => 'allow', 'retry_after' => 0];
            }
            elseif ($decision['action'] === 'allow') {
                $data = $this->network->evaluate($snapshot, $this->client());
                $decision = $data['decision'] ?? $this->browser->decision($snapshot, $this->client()) ?? $decision;
            }
            $response = $this->response->raw('')->withStatus($decision['status'])->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Galaxy-Waf-Protocol', '1')->withHeader('X-Galaxy-Waf-Action', $decision['action']);
            $response = $response->withHeader('X-Galaxy-Waf-Country', $data['country'])->withHeader('X-Galaxy-Waf-Source', $data['source'])
                ->withHeader('X-Galaxy-Waf-Data', $data['data_status']);
            return $decision['retry_after'] > 0 ? $response->withHeader('Retry-After', (string) $decision['retry_after']) : $response;
        } catch (Throwable $e) {
            // Credentials and backend error details must never reach the protected site's visitor.
            $code = $e instanceof AppException && in_array($e->getCode(), [400,401], true) ? 401 : 503;
            return $this->response->raw('')->withStatus($code)->withHeader('Cache-Control', 'no-store');
        }
    }

    public function challenge()
    {
        try {
            $snapshot = $this->configs->authenticate((string) $this->request->header('X-Galaxy-Waf-Token', ''));
            if ($this->bans->decision($snapshot, (string) $this->request->header('X-Galaxy-Waf-Client', ''))['status'] === 403) throw new AppException(403, '当前请求暂被安全策略限制');
            if (($this->network->evaluate($snapshot, $this->client())['decision']['status'] ?? 204) === 403) throw new AppException(403, '当前请求暂被安全策略限制');
            $page = $this->pages->render($this->browser->issue($snapshot, $this->client()), WafBrowserToken::cookieName($snapshot->policy_id));
            return $this->response->raw($page['html'])->withStatus(401)->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')
                ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Content-Security-Policy', $page['csp']);
        } catch (Throwable $e) { return $this->problem($e, false); }
    }

    public function solve()
    {
        try {
            $snapshot = $this->configs->authenticate((string) $this->request->header('X-Galaxy-Waf-Token', ''));
            if ($this->bans->decision($snapshot, (string) $this->request->header('X-Galaxy-Waf-Client', ''))['status'] === 403) throw new AppException(403, '当前请求暂被安全策略限制');
            if (($this->network->evaluate($snapshot, $this->client())['decision']['status'] ?? 204) === 403) throw new AppException(403, '当前请求暂被安全策略限制');
            $client = $this->client();
            $result = $this->browser->solve($snapshot, $client, $this->request->input('token'), $this->request->input('answer'));
            $cookie = WafBrowserToken::cookieName($snapshot->policy_id) . '=' . $result['token'] . '; Path=/; Max-Age=' . $result['max_age'] . '; HttpOnly; SameSite=Lax';
            if ($this->request->header('X-Galaxy-Waf-Scheme', '') === 'https') $cookie .= '; Secure';
            return $this->response->raw('{"ok":true}')->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withHeader('Set-Cookie', $cookie);
        } catch (Throwable $e) { return $this->problem($e, true); }
    }

    private function client(): array
    {
        $client = [];
        foreach (['ip' => 'Client', 'host' => 'Host', 'ua' => 'Agent', 'method' => 'Method', 'accept' => 'Accept',
            'upgrade' => 'Upgrade', 'path' => 'Path', 'clearance' => 'Clearance'] as $key => $header) $client[$key] = (string) $this->request->header('X-Galaxy-Waf-' . $header, '');
        return $client;
    }

    private function problem(Throwable $e, bool $json)
    {
        $code = $e instanceof AppException && in_array($e->getCode(), [400,401,403,422,429], true) ? $e->getCode() : 503;
        $message = $code === 503 ? '安全验证暂不可用，请稍后刷新' : $e->getMessage();
        $body = $json ? json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE) : htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $response = $this->response->raw($body)->withStatus($code)->withHeader('Content-Type', $json ? 'application/json' : 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff');
        return $code === 429 ? $response->withHeader('Retry-After', '60') : $response;
    }
}
