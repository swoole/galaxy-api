<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Services\WebhookServices;
use Hyperf\Di\Annotation\Inject;

class WebhooksController extends AbstractController
{
    /**
     * @Inject
     */
    #[Inject]
    protected WebhookServices $webhookService;

    /**
     * Githook.
     */
    public function githook($org, $project)
    {
        $this->webhookService->handleGithook($this->request, (int) $org, (int) $project);

        return $this->success();
    }

}
