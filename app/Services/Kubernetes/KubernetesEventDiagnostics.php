<?php

namespace App\Services\Kubernetes;

use Throwable;

final class KubernetesEventDiagnostics
{
    public function __construct(private KubernetesApiClient $kubernetes) {}

    public function failureSummary(array $credential, string $namespace, int $limit = 5): string
    {
        try {
            $events = $this->kubernetes->request(
                $credential,
                'GET',
                '/api/v1/namespaces/' . rawurlencode($namespace) . '/events',
                ['query' => ['fieldSelector' => 'type=Warning']]
            );
            $pods = $this->kubernetes->request(
                $credential,
                'GET',
                '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods'
            );
            return self::summarize(
                (array) ($events['items'] ?? []),
                (array) ($pods['items'] ?? []),
                $limit
            );
        } catch (Throwable) {
            // Diagnostics must never hide the original installation failure.
            return '';
        }
    }

    public static function summarize(array $events, array $pods, int $limit = 5): string
    {
        $items = [];
        foreach ($pods as $pod) {
            $podName = (string) ($pod['metadata']['name'] ?? 'unknown');
            foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $container) {
                $waiting = (array) ($container['state']['waiting'] ?? []);
                $message = trim((string) ($waiting['message'] ?? ''));
                $reason = trim((string) ($waiting['reason'] ?? ''));
                if ($message === '' && $reason === '') {
                    continue;
                }
                self::append($items, sprintf(
                    'Pod %s/%s：%s',
                    $podName,
                    (string) ($container['name'] ?? 'container'),
                    $message !== '' ? $message : $reason
                ), self::priority($reason . ' ' . $message));
            }
        }
        foreach ($events as $event) {
            if ((string) ($event['type'] ?? '') !== 'Warning') {
                continue;
            }
            $message = trim((string) ($event['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $object = (array) ($event['involvedObject'] ?? []);
            $label = trim((string) ($object['kind'] ?? '') . ' ' . (string) ($object['name'] ?? ''));
            $reason = trim((string) ($event['reason'] ?? 'Warning'));
            self::append(
                $items,
                ($label === '' ? 'Kubernetes' : $label) . ' [' . $reason . ']：' . $message,
                self::priority($reason . ' ' . $message)
            );
        }
        if ($items === []) {
            return '';
        }
        uasort($items, static fn (array $left, array $right): int => $right['priority'] <=> $left['priority']);
        $messages = array_slice(array_column($items, 'message'), 0, max(1, $limit));
        return mb_substr('Kubernetes Events：' . implode('；', $messages), 0, 1800);
    }

    private static function append(array &$items, string $message, int $priority): void
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?: '';
        if ($message === '') {
            return;
        }
        // Events repeat during image pull backoff. Keep one copy of each useful
        // message while preserving distinct Pods and failure causes.
        $key = hash('sha256', $message);
        $items[$key] = ['message' => $message, 'priority' => $priority];
    }

    private static function priority(string $message): int
    {
        return match (true) {
            preg_match('/ImagePullBackOff|ErrImagePull|failed to pull|not found|authorization failed/i', $message) === 1 => 100,
            preg_match('/FailedScheduling|FailedMount|FailedAttachVolume/i', $message) === 1 => 90,
            preg_match('/CrashLoopBackOff|OOMKilled|CreateContainerError/i', $message) === 1 => 80,
            default => 10,
        };
    }
}
