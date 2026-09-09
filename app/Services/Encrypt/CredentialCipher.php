<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Services\Encrypt;

use App\Exception\AppException;

class CredentialCipher
{
    private const PREFIX = 'swc1:';

    private const AAD = 'galaxy-docker-credential-v1';

    private ?string $key = null;

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::AAD,
            $nonce,
            $this->key()
        );

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        if (! str_starts_with($payload, self::PREFIX)) {
            throw new AppException(500, '平台敏感凭据格式无法识别');
        }
        $decoded = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($decoded === false || strlen($decoded) <= $nonceLength) {
            throw new AppException(500, '平台敏感凭据数据已损坏');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($decoded, $nonceLength),
            self::AAD,
            substr($decoded, 0, $nonceLength),
            $this->key()
        );
        if ($plaintext === false) {
            throw new AppException(500, '平台敏感凭据解密失败，请检查 SWARM_CREDENTIAL_KEY');
        }

        return $plaintext;
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $configured = trim((string) config('docker.credential_key', ''));
        if ($configured !== '') {
            $decoded = base64_decode($configured, true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new AppException(500, 'SWARM_CREDENTIAL_KEY 必须是 base64 编码的 32 字节密钥');
            }
            return $this->key = $decoded;
        }

        $path = (string) config('docker.credential_key_file');
        if (is_file($path)) {
            return $this->key = $this->readKeyFile($path);
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new AppException(500, '无法创建平台凭据密钥目录');
        }
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (is_file($path)) {
                return $this->key = $this->readKeyFile($path);
            }
            throw new AppException(500, '无法创建平台凭据密钥文件');
        }
        try {
            chmod($path, 0600);
            if (fwrite($handle, base64_encode($key)) === false) {
                throw new AppException(500, '写入平台凭据密钥失败');
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }

        return $this->key = $key;
    }

    private function readKeyFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new AppException(500, '无法读取平台凭据密钥文件');
        }
        $decoded = base64_decode(trim($contents), true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new AppException(500, '平台凭据密钥文件无效');
        }
        return $decoded;
    }
}
