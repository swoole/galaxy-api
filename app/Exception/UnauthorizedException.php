<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Exception;

use App\Constants\ErrorCode;
use Hyperf\Server\Exception\ServerException;

class UnauthorizedException extends ServerException
{
    /**
     * 上下文信息.
     *
     * @var array
     */
    protected $context = [];

    public function __construct(?string $message = null, array $context = [])
    {
        if (is_null($message)) {
            $message = ErrorCode::getMessage(ErrorCode::TOKEN_INVALID);
        }
        $this->context = $context;
        parent::__construct($message, ErrorCode::TOKEN_INVALID);
    }

    /**
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }
}
