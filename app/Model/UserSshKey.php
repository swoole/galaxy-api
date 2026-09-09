<?php

namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Support\Functions;
use Swoole\Coroutine\System;

class UserSshKey extends Model
{
    protected ?string $table = 'user_ssh_key';
    protected array $guarded = [];
    protected array $casts = ['id' => 'integer', 'uid' => 'integer', 'generate_at' => 'integer'];

    public function createUserSshKey(array $data)
    {
        return self::create([
            'uid' => (int) $data['uid'], 'pubkey' => (string) ($data['pubkey'] ?? ''),
            'privatekey' => (string) ($data['privatekey'] ?? ''), 'generate_at' => time(),
        ]);
    }

    public function show(int $uid)
    {
        $key = $this->where('uid', $uid)->select('pubkey', 'generate_at')->first();
        if ($key !== null) {
            $key['fingerprint'] = ['sha256' => Functions::calcuSshKeySha256Fingerprint($key['pubkey'])];
        }
        return $key;
    }

    public function confirmPrivkey(int $uid, string $hash, string $algo = 'SHA1'): bool
    {
        $privateKey = (string) $this->where('uid', $uid)->value('privatekey');
        return $privateKey !== '' && hash_equals(sha1($privateKey), $hash);
    }

    public function authed(int $uid, ?string $repo): bool
    {
        if (empty($repo)) {
            return true;
        }
        $privateKey = (string) $this->where('uid', $uid)->value('privatekey');
        if ($privateKey === '') {
            return false;
        }
        $parsed = parse_url($repo);
        $target = empty($parsed['port']) ? explode(':', $repo)[0]
            : sprintf('-p %d %s@%s', $parsed['port'], $parsed['user'], $parsed['host']);
        $tmp = tmpfile();
        if ($tmp === false) {
            return false;
        }
        fwrite($tmp, $privateKey);
        $path = stream_get_meta_data($tmp)['uri'];
        chmod($path, 0600);
        $result = System::exec(sprintf(
            'ssh -o BatchMode=yes -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -T -i %s %s',
            escapeshellarg($path), $target
        ));
        fclose($tmp);
        return $result['code'] !== 255;
    }

    public function fastAuthed(int $uid, ?string $repo = null): void
    {
        if (! $this->authed($uid, $repo)) {
            throw new AppException(ErrorCode::SSH_NOT_CONFIGURED_FOR_GITREPO,
                sprintf('未配置仓库 %s 所需的 SSH 公钥', $repo));
        }
    }
}
