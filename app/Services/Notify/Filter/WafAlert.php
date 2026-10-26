<?php

namespace App\Services\Notify\Filter;

final class WafAlert
{
    public function beforeNotify(array $params): array
    {
        foreach (['title', 'message'] as $field) $params[$field] = htmlspecialchars((string) ($params[$field] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return $params;
    }
}
