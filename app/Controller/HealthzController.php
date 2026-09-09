<?php

namespace App\Controller;

class HealthzController extends AbstractController
{
    public function healthz()
    {
        return $this->success();
    }
}
