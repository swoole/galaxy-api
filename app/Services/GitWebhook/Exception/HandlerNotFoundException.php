<?php

namespace App\Services\GitWebhook\Exception;

class HandlerNotFoundException extends \InvalidArgumentException
{
    public function __construct(string $message = 'the appropriate handler could not be found', int $code = 404)
    {
        parent::__construct($message, $code);
    }
}
