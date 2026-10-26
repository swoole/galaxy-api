<?php

namespace App\Services\Gateway;

final class WafClientIp
{
    public function normalize(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $binary = inet_pton($ip);
        // Normalize IPv4-mapped IPv6 addresses to the same identity as their IPv4 counterpart.
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $binary = substr($binary, 12);
        return inet_ntop($binary) ?: null;
    }

    public function matches(string $ip, array $cidrs): bool
    {
        $canonical = $this->normalize($ip);
        if ($canonical === null) return false;
        $address = inet_pton($canonical);
        foreach ($cidrs as $cidr) {
            [$base, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
            if ($prefix !== null && ! ctype_digit($prefix)) continue;
            $original = @inet_pton($base);
            $normalized = $this->normalize($base);
            if ($normalized === null) continue;
            $network = inet_pton($normalized);
            if (strlen($network) !== strlen($address)) continue;
            $bits = $prefix === null ? strlen($network) * 8 : (int) $prefix;
            if ($prefix !== null && strlen($original ?: '') === 16 && strlen($network) === 4) $bits -= 96;
            if ($bits < 0 || $bits > strlen($network) * 8) continue;
            $whole = intdiv($bits, 8); $rest = $bits % 8;
            if (substr($network, 0, $whole) !== substr($address, 0, $whole)) continue;
            if ($rest === 0 || ((ord($network[$whole]) ^ ord($address[$whole])) & (0xff << (8 - $rest))) === 0) return true;
        }
        return false;
    }
}
