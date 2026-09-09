<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Services\Encrypt\EncryptService;
use Hyperf\Di\Annotation\Inject;

trait TraitEncrypt
{
    /**
     * 解密/加密结果仅是运行时缓存，不能写入 Model attributes。
     *
     * Model 保存时会把 attributes 中的 decrypt_* / encrypt_* 当成数据库列，
     * 因此这里使用独立属性保存缓存。
     */
    private array $decryptedFieldCache = [];

    private array $encryptedFieldCache = [];

    /**
     * @Inject
     */
    #[Inject]
    protected EncryptService $encryptService;

    /**
     * 解密字段.
     */
    public function decryptField(string $field, $value = null) : string
    {
        if (! isset($this->decryptedFieldCache[$field])) {
            $this->decryptedFieldCache[$field] = $this->encryptService->decryptFast(
                $value ?? $this->attributes[$field]
            );
        }

        return $this->decryptedFieldCache[$field];
    }

    /**
     * 加密字段.
     */
    public function encryptField(string $field, $value = null) : string
    {
        if (! isset($this->encryptedFieldCache[$field])) {
            $this->encryptedFieldCache[$field] = $this->encryptService->encryptFast(
                $value ?? $this->attributes[$field]
            );
        }

        return $this->encryptedFieldCache[$field];
    }

    /**
     * 设置解密字段缓存.
     */
    public function decryptFieldCache(string $field, $value)
    {
        $this->decryptedFieldCache[$field] = $value;
    }

    /**
     * 设置加密字段缓存.
     */
    public function encryptFieldCache(string $field, $value)
    {
        $this->encryptedFieldCache[$field] = $value;
    }
}
