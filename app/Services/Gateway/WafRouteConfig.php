<?php

namespace App\Services\Gateway;

/** Both regular and SSE routers keep pointing at the same protected service. */
final class WafRouteConfig
{
    public static function apply(array $configuration, array $routes): array
    {
        foreach ($routes as $key => $runtime) {
            [$source, $id] = explode(':', $key);
            $name = $source === 'gateway' ? 'cg-vhost-' . $id . '-svc' : 'cg-project-route-' . $id . '-svc';
            if (! isset($configuration['http']['services'][$name])) {
                throw new \LogicException('WAF references a missing gateway service: ' . $name);
            }
            $configuration['http']['services'][$name]['loadBalancer']['servers'] = [['url' => $runtime['url']]];
            $configuration['http']['services'][$name]['loadBalancer']['passHostHeader'] = true;
        }
        return $configuration;
    }
}
