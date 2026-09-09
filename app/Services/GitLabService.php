<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Exception\AppException;
use Hyperf\Guzzle\ClientFactory;

/**
 * @see https://docs.gitlab.com/ee/api/api_resources.html
 */
class GitLabService
{
    /**
     * @see https://docs.gitlab.com/ee/api/members.html#valid-access-levels
     */
    public const NO_ACCESS = 0; // 没权限

    public const MINIMAL_ACCESS = 5;

    public const GUEST = 10;

    public const REPORTER = 20;

    public const DEVELOPER = 30;

    public const MAINTAINER = 40;

    public const OWNER = 50;

    private $api = 'https://gitlab.com/api/v4';

    private $token = '';

    /**
     * @var ClientFactory
     */
    private $clientFactory;

    public function __construct(ClientFactory $clientFactory)
    {
        $this->clientFactory = $clientFactory;
    }

    public function setApi(string $api)
    {
        $this->api = $api;

        return $this;
    }

    public function setToken(string $token)
    {
        $this->token = $token;

        return $this;
    }

    public function getToken(): string
    {
        return 'Bearer ' . $this->token;
    }

    /**
     * 创建仓库.
     * @see https://docs.gitlab.com/ee/api/projects.html#create-project
     * @param string $name 新项目的名称。如果未提供，则等于路径。
     * @param string $path 新项目的存储库名称。如果未提供，则基于名称生成（生成为带破折号的小写）。
     * @param string $description 简短的项目描述
     * @param string $visibility 项目可见度 private | public| internal https://docs.gitlab.com/ee/api/projects.html#project-visibility-level
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return mixed
     */
    public function createRepo(string $name, string $path = '', string $description = '', string $visibility = 'private')
    {
        $json = [
            'name' => $name,
            'path' => $path,
            'description' => $description,
            'visibility' => $visibility,
        ];

        try {
            $client = $this->clientFactory->create();
            $res = $client->post(
                $this->api . '/projects',
                [
                    'headers' => ['Authorization' => $this->getToken()],
                    'json' => $json,
                ]
            );

            return json_decode($res->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw new AppException($e->getCode(), (string) $e->getResponse()->getBody());
        }
    }

    /**
     * @param $idOrPath
     * @param $user_id
     * @param int $access_level
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return mixed
     * @see https://docs.gitlab.com/ee/api/members.html#add-a-member-to-a-group-or-project
     */
    public function addMembers(int $id, $user_id, $access_level = self::DEVELOPER)
    {
        $json = [
            'id' => $id,
            'user_id' => $user_id,
            'access_level' => $access_level,
        ];

        try {
            $client = $this->clientFactory->create();
            $res = $client->post(
                $this->api . "/projects/{$id}/members",
                [
                    'headers' => ['Authorization' => $this->getToken()],
                    'json' => $json,
                ]
            );

            return json_decode($res->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw new AppException($e->getCode(), (string) $e->getResponse()->getBody());
        }
    }

    /**
     * 获取项目成员.
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return mixed
     */
    public function getMembers(int $id)
    {
        try {
            $client = $this->clientFactory->create();
            $res = $client->get(
                $this->api . "/projects/{$id}/members",
                [
                    'headers' => ['Authorization' => $this->getToken()],
                ]
            );

            return json_decode($res->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw new AppException($e->getCode(), (string) $e->getResponse()->getBody());
        }
    }

    /**
     * 搜索用户.
     * @param $username
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return mixed
     */
    public function searchUsers($username)
    {
        try {
            $client = $this->clientFactory->create();
            $res = $client->get(
                $this->api . "/users?username={$username}",
                [
                    'headers' => ['Authorization' => $this->getToken()],
                ]
            );

            return json_decode($res->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw new AppException($e->getCode(), (string) $e->getResponse()->getBody());
        }
    }
}
