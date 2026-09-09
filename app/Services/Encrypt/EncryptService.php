<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Services\Encrypt;

use App\Exception\AppException;

class EncryptService
{
    private const VERSION = 'v1';

    private const VERSION_KEY = 'iz3B7DY4';

    private const SALT_SIZE = 8;

    private const SALT_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private ?string $key = null;

    /**
     * 快速加密单个字符串.
     */
    public function encryptFast(string $str): string
    {
        if ($str === '') {
            return '';
        }

        return $this->encrypts(['key' => $str])['key'];
    }

    /**
     * 批量加密.
     */
    public function encrypts(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $k => $v) {
            $result[$k] = $this->encrypt($v);
        }

        return $result;
    }

    /**
     * 快速解密单个字符串.
     */
    public function decryptFast(string $str): string
    {
        if ($str === '') {
            return '';
        }

        return $this->decrypts(['key' => $str])['key'];
    }

    /**
     * 批量解密.
     */
    public function decrypts(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $k => $v) {
            $result[$k] = $this->decrypt($v);
        }

        return $result;
    }

    /**
     * 加密.
     */
    private function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $salt = $this->randomSalt();
        $iv = $salt . self::VERSION_KEY;
        $ciphertext = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            $this->getKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        return $salt . self::VERSION . base64_encode($ciphertext);
    }

    /**
     * 解密.
     */
    private function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        $salt = substr($ciphertext, 0, self::SALT_SIZE);
        $version = substr($ciphertext, self::SALT_SIZE, 2);

        // 非加密字符串直接返回原文
        if ($version !== self::VERSION) {
            return $ciphertext;
        }

        $encrypted = base64_decode(substr($ciphertext, self::SALT_SIZE + 2));
        if ($encrypted === false) {
            return $ciphertext;
        }

        $iv = $salt . self::VERSION_KEY;
        $plaintext = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            $this->getKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plaintext === false) {
            throw new AppException(500, '解密失败，请检查 ENCRYPT_KEY');
        }

        return $plaintext;
    }

    private function getKey(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        // 优先从环境变量获取 base64 编码的密钥
        $envKey = trim((string) config('app.encrypt.key', ''));
        if ($envKey !== '') {
            $decoded = base64_decode($envKey, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new AppException(500, 'ENCRYPT_KEY 必须是 base64 编码的 32 字节密钥');
            }

            return $this->key = $decoded;
        }

        // 从密钥文件读取，匹配 Go 服务的行为：读取文件偏移 16 字节取 32 字节
        $path = (string) config('app.encrypt.key_file');
        if (! is_file($path)) {
            throw new AppException(500, '密钥文件不存在: ' . $path);
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new AppException(500, '无法读取密钥文件: ' . $path);
        }
        if (strlen($contents) < 48) {
            throw new AppException(500, '密钥文件内容不足 48 字节: ' . $path);
        }

        return $this->key = substr($contents, 16, 32);
    }

    private function randomSalt(): string
    {
        $alphabetLen = strlen(self::SALT_ALPHABET);
        $salt = '';
        for ($i = 0; $i < self::SALT_SIZE; $i++) {
            $salt .= self::SALT_ALPHABET[random_int(0, $alphabetLen - 1)];
        }

        return $salt;
    }
}
