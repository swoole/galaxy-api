<?php

namespace App\Services\Project;

class RepositoryIdentityService
{
    /** @param array<int, string|null> $candidates */
    public function matches(string $configured, array $candidates): bool
    {
        $expected = $this->normalize($configured);
        if ($expected === null) {
            return false;
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $this->normalize($candidate) === $expected) {
                return true;
            }
        }
        return false;
    }

    public function normalize(string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $host = '';
        $path = '';
        if (preg_match('#^[^@/:\s]+@([^:/\s]+):(.+)$#', $reference, $matches)) {
            $host = $matches[1];
            $path = $matches[2];
        } else {
            $parts = parse_url($reference);
            if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
                return null;
            }
            $host = (string) $parts['host'];
            $path = (string) $parts['path'];
        }

        $host = strtolower(rtrim($host, '.'));
        $path = preg_replace('#/+#', '/', trim($path, '/')) ?? '';
        $path = preg_replace('/\.git$/i', '', $path) ?? '';
        $segments = explode('/', $path);
        if ($host === '' || $path === '' || array_intersect($segments, ['.', '..']) !== []) {
            return null;
        }
        // Git HTTP and SSH endpoints commonly use different ports. Repository
        // identity is therefore the canonical host plus owner/repository path.
        return $host . '/' . $path;
    }
}
