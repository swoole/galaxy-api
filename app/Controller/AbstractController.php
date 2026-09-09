<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Exception\UnauthorizedException;
use App\Model\Org;
use App\Support\Functions;
use App\Support\Response;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Support\MimeTypeExtensionGuesser;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

abstract class AbstractController
{
    /**
     * @Inject
     * @var ContainerInterface
     */
    #[Inject]
    protected $container;

    /**
     * @Inject
     * @var RequestInterface
     */
    #[Inject]
    protected $request;

    /**
     * @Inject
     * @var ResponseInterface
     */
    #[Inject]
    protected $response;

    /**
     * @Inject
     * @var ValidatorFactoryInterface
     */
    #[Inject]
    protected $validationFactory;

    /**
     * @Inject
     */
    #[Inject]
    protected MimeTypeExtensionGuesser $guesser;

    /**
     * 响应成功信息.
     *
     * @param array $data
     * @return PsrResponseInterface
     */
    protected function success($data = [], string $msg = 'success', int $code = 0, array $extend = [])
    {
        $json = Response::json($code, $msg, $data, $extend);

        return $this->response->json($json);
    }

    /**
     * 响应失败信息.
     *
     * @param array $data
     * @return PsrResponseInterface
     */
    protected function failed(string $msg = 'failed', $data = [], int $code = 1, array $extend = [])
    {
        return $this->success($data, $msg, $code, $extend);
    }

    /**
     * 返回所有字段的参数验证
     *
     * @throws ValidationException
     * @return array
     */
    protected function validateAll(array $rules, array $messages = [], array $params = [])
    {
        if (empty($params)) {
            $params = $this->request->all();
        }

        $validator = $this->validationFactory->make($params, $rules, $messages);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $params;
    }

    /**
     * 只返回被验证字段的参数验证
     *
     * @throws ValidationException
     * @return array
     */
    protected function validate(array $rules, array $messages = [])
    {
        $params = Functions::arrayOnlyKeys($this->request->all(), array_keys($rules));

        return $this->validateAll($rules, $messages, $params);
    }

    /**
     * 验证管理员组织.
     */
    protected function validateAdminOrg()
    {
        if (Functions::getContextValue('org_id') != Org::adminOrg()) {
            throw new UnauthorizedException('the permission level is insufficient.');
        }
    }

    /**
     * 下载文件内容.
     */
    protected function downloadContent(string $content, string $filename, $mimeType = null): PsrResponseInterface
    {
        $parts = explode('.', $filename);
        $extension = $parts[count($parts) - 1];
        $contentType = $mimeType ?: ($this->guesser->guessMimeType($extension) ?: 'application/octet-stream');

        return $this->response->withHeader('content-description', 'File Transfer')
            ->withHeader('content-type', $contentType)
            ->withHeader('content-disposition', "attachment; filename={$filename}; filename*=UTF-8''" . rawurlencode($filename))
            ->withHeader('content-transfer-encoding', 'binary')
            ->withHeader('pragma', 'public')
            ->withBody(new SwooleStream($content));
    }
}
