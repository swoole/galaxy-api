<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Exception\Handler;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Exception\UnauthorizedException;
use App\Support\Response;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $appEnv = 'prod';

    public function __construct(StdoutLoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->appEnv = config('app_env', 'prod');
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        // 拦截强制转为Json
        $code = $throwable->getCode() ?: 1;
        if (! is_int($code)) {
            $code = 1;
        }
        $msg = $throwable->getMessage() . '(' . $throwable->getCode() . ')';
        $data = [];
        $extend = [];
        if ($throwable instanceof UnauthorizedException) {
            $code = $throwable->getCode();
            $data = $throwable->getContext();
        } elseif ($throwable instanceof AppException) {
            // 避免其他业务抛出401导致帐号退出
            if ($code == ErrorCode::TOKEN_INVALID) {
                $code += 9000;
            }
            $data = $throwable->getContext();
        } elseif ($throwable instanceof ValidationException) {
            $code = ErrorCode::PARAMS_ERROR;
            $errorMessage = current(current($throwable->errors()));
            $msg = $errorMessage;
//            $data = [
//                'errors' => $errorMessage,
//            ];
        } else {
            // 记录错误日志
            $this->logger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
            $this->logger->error($throwable->getTraceAsString());
        }

        // 开发模式注入debug信息
        $this->withDebug($extend, $throwable);

        $json = Response::json((int) $code, $msg, $data, $extend);
        $text = json_encode($json);
        return $response->withHeader('Content-Type', 'application/json')->withBody(new SwooleStream($text));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }

    /**
     * 输出debug信息.
     */
    protected function withDebug(array &$extend, Throwable $e): void
    {
        // 仅dev时开启
        if ($this->appEnv != 'dev') {
            return;
        }

        $extend['debug'] = [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
            'previous' => $e->getPrevious(),
        ];
    }
}
