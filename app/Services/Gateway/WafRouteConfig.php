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
            if (! preg_match('/^[a-f0-9]{64}$/D', $runtime['gateway_token'] ?? '')) throw new \LogicException('WAF gateway credential missing or invalid');
            $middleware = 'cg-waf-' . $source . '-' . $id . '-auth';
            $configuration['http']['middlewares'][$middleware] = ['headers' => ['customRequestHeaders' => ['X-Galaxy-Waf-Gateway' => $runtime['gateway_token']]]];
            foreach ($configuration['http']['routers'] as &$router) {
                if (($router['service'] ?? '') === $name) $router['middlewares'] = array_values(array_unique(array_merge($router['middlewares'] ?? [], [$middleware])));
            }
            unset($router);
            $configuration['http']['services'][$name]['loadBalancer']['servers'] = [['url' => $runtime['url']]];
            $configuration['http']['services'][$name]['loadBalancer']['passHostHeader'] = true;
        }
        return $configuration;
    }
}
