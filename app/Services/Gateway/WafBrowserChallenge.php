<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafRuntimeConfig;

final class WafBrowserChallenge
{
    public function __construct(private WafBrowserToken $tokens, private WafChallengeStore $store, private WafClientIp $ips) {}

    public function assertAvailable(WafRuntimeConfig $snapshot): void
    {
        $rule = $snapshot->settings['browser'] ?? ['enabled' => false];
        if ($rule['enabled'] && $rule['type'] === 'captcha' && ! extension_loaded('gd')) throw new AppException(503, '验证码服务暂不可用');
    }

    public function decision(WafRuntimeConfig $snapshot, array $client): ?array
    {
        $settings = $snapshot->settings; $rule = $settings['browser'] ?? ['enabled' => false];
        if (! $rule['enabled'] || $settings['mode'] === 'off') return null;
        $ip = $this->ips->normalize((string) ($client['ip'] ?? ''));
        $path = WafRequestPath::normalize((string) ($client['path'] ?? '/'));
        if ($this->ips->matches($ip ?? '', $settings['ip_allowlist'])
            || in_array($path, array_merge($settings['excluded_paths'], $rule['excluded_paths']), true)) return null;
        foreach ($rule['excluded_prefixes'] as $prefix) if ($prefix === '/' || $path === rtrim($prefix, '/') || str_starts_with($path, rtrim($prefix, '/') . '/')) return null;
        $context = $this->tokens->context($snapshot, $client);
        if (($client['clearance'] ?? '') !== '') {
            try { $this->tokens->verify('clearance', $client['clearance'], $context, $rule['clearance_seconds']); return null; }
            catch (AppException) { /* Invalid/expired cookies require a new challenge. */ }
        }
        // Request headers are spoofable. Streams/upgrades never get HTML, but need
        // a clearance or an explicit path/non-browser exception like any API call.
        $html = ($client['method'] ?? '') === 'GET' && stripos((string) ($client['accept'] ?? ''), 'text/html') !== false
            && empty($client['upgrade']) && stripos((string) ($client['accept'] ?? ''), 'text/event-stream') === false;
        if (! $html && $rule['non_browser'] === 'bypass') return null;
        if ($settings['mode'] === 'monitor') return ['status' => 204, 'action' => 'browser_monitor', 'retry_after' => 0];
        return ['status' => $html ? 401 : 403, 'action' => $html ? 'browser_challenge' : 'browser_block', 'retry_after' => 0];
    }

    public function issue(WafRuntimeConfig $snapshot, array $client): array
    {
        $rule = $this->rule($snapshot); $context = $this->tokens->context($snapshot, $client);
        $this->store->limit($context);
        $nonce = bin2hex(random_bytes(16)); $answer = $rule['type'] === 'captcha' ? (string) random_int(100000, 999999) : '';
        $state = $context + ['type' => $rule['type'], 'difficulty' => $rule['difficulty'],
            'answer' => hash_hmac('sha256', $answer, $nonce)];
        $this->store->issue($nonce, $state, $rule['challenge_seconds']);
        return ['type' => $rule['type'], 'nonce' => $nonce, 'difficulty' => $rule['difficulty'],
            'seconds' => $rule['challenge_seconds'],
            'token' => $this->tokens->issue('challenge', $context, $rule['challenge_seconds'], ['n' => $nonce]),
            'image' => $answer === '' ? '' : $this->captcha($answer)];
    }

    public function solve(WafRuntimeConfig $snapshot, array $client, mixed $token, mixed $answer): array
    {
        $rule = $this->rule($snapshot); $context = $this->tokens->context($snapshot, $client);
        if (! is_string($token) || ! is_string($answer) || strlen($answer) > 16) throw new AppException(422, '验证参数无效');
        $claims = $this->tokens->verify('challenge', $token, $context, $rule['challenge_seconds']);
        $this->store->limit($context);
        $nonce = $claims['n'] ?? ''; $state = $this->store->claim(is_string($nonce) ? $nonce : '');
        if (($state['s'] ?? '') !== $context['s'] || ($state['c'] ?? '') !== $context['c'] || ($state['type'] ?? '') !== $rule['type']) throw new AppException(403, '验证上下文已变化，请刷新页面');
        $valid = $rule['type'] === 'captcha'
            ? preg_match('/^[0-9]{6}$/D', $answer) && hash_equals($state['answer'], hash_hmac('sha256', $answer, $nonce))
            : preg_match('/^(?:0|[1-9][0-9]{0,7})$/D', $answer) && self::proof($nonce, $answer, (int) $state['difficulty']);
        if (! $valid) throw new AppException(403, '验证未通过，请刷新页面重试');
        return ['token' => $this->tokens->issue('clearance', $context, $rule['clearance_seconds']), 'max_age' => $rule['clearance_seconds']];
    }

    public static function proof(string $nonce, string $answer, int $bits): bool
    {
        if ($bits < 12 || $bits > 20) return false;
        $hash = hash('sha256', $nonce . ':' . $answer, true);
        $whole = intdiv($bits, 8); $remaining = $bits % 8;
        return substr($hash, 0, $whole) === str_repeat("\0", $whole) && ($remaining === 0 || ord($hash[$whole]) < (1 << (8 - $remaining)));
    }

    private function rule(WafRuntimeConfig $snapshot): array
    {
        $rule = $snapshot->settings['browser'] ?? ['enabled' => false];
        if (! $rule['enabled'] || $snapshot->settings['mode'] !== 'block') throw new AppException(403, '当前防护策略无需验证');
        $this->assertAvailable($snapshot);
        return $rule;
    }

    private function captcha(string $answer): string
    {
        $image = imagecreatetruecolor(210, 66); $background = imagecolorallocate($image, 242, 246, 252);
        imagefill($image, 0, 0, $background);
        for ($i = 0; $i < 14; ++$i) imageline($image, random_int(0,209), random_int(0,65), random_int(0,209), random_int(0,65), imagecolorallocate($image, random_int(150,210), random_int(150,210), random_int(150,210)));
        foreach (str_split($answer) as $index => $digit) {
            $glyph = imagecreatetruecolor(26, 38); imagefill($glyph, 0, 0, $background);
            imagestring($glyph, 5, 8, 10, $digit, imagecolorallocate($glyph, random_int(15,75), random_int(25,85), random_int(40,115)));
            $rotated = imagerotate($glyph, random_int(-18,18), $background);
            imagecopyresampled($image, $rotated, 10 + $index * 32, random_int(8,16), 0, 0, 30, 44, imagesx($rotated), imagesy($rotated));
            imagedestroy($glyph); imagedestroy($rotated);
        }
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
