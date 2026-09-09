<?php

namespace App\Support;

final class NetworkEndpoint
{
    /**
     * Localhost, private/reserved addresses and hosts resolving to one of
     * those ranges must bypass the process HTTP(S) proxy.
     */
    public static function isLocalOrPrivate(string $endpoint): bool
    {
        $host = strtolower(trim((string) parse_url($endpoint, PHP_URL_HOST), '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPrivateAddress($host);
        }
        $addresses = @gethostbynamel($host);
        if (! is_array($addresses)) {
            return false;
        }
        foreach ($addresses as $address) {
            if (self::isPrivateAddress($address)) {
                return true;
            }
        }
        return false;
    }

    private static function isPrivateAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
