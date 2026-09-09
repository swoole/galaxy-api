<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Support;

use App\Exception\AppException;
use Symfony\Component\Process\Process;

class Git
{
    public static $sshKeyAlgos = [
        'rsa' => 'ssh-keygen -t rsa -b 4096',
        'ed25519' => 'ssh-keygen -t ed25519',
        'ecdsa' => 'ssh-keygen -t ecdsa -b 521',
    ];

    public static function getRemoteBranches(string $path): array
    {
        $process = new Process(['git', '-C', $path, 'branch', '-r', '--format=%(refname:short)']);
        $process->run();
        $branches = [];
        if ($process->isSuccessful()) {
            foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $branch) {
                if ($branch === '' || str_contains($branch, '->')) {
                    continue;
                }
                $branches[] = explode('/', trim($branch), 2)[1] ?? trim($branch);
            }
        }
        return $branches;
    }

    public static function getTags(string $path): array
    {
        $process = new Process(['git', '-C', $path, 'tag']);
        $process->run();
        $tags = [];
        if ($process->isSuccessful()) {
            $output = trim($process->getOutput());
            $tags = $output === '' ? [] : preg_split('/\R/', $output);
            $tags = array_reverse($tags);
        }
        return $tags;
    }

    public static function getCommitId(string $path, int $limit = 20): array
    {
        $process = new Process(['git', '-C', $path, 'log', '-n', (string) $limit, '--pretty=format:%h']);
        $process->run();
        $commits = [];
        if ($process->isSuccessful()) {
            $output = trim($process->getOutput());
            $commits = $output === '' ? [] : preg_split('/\R/', $output);
        }
        return $commits;
    }

    public static function createSshKey(string $account, $filePath = '', $algo = 'ed25519')
    {
        if (!isset(self::$sshKeyAlgos[$algo])) {
            throw new AppException(422, '不支持算法 ' . $algo);
        }
        $keygen = self::$sshKeyAlgos[$algo];
        // -N '' 默认密码为空   -f 指定生成路径 ~/.ssh/id_rsa
        $command = array_merge(explode(' ', $keygen), ['-N', '', '-f', $filePath, '-C', $account]);
        $process = new Process($command);
        $process->setInput("y\n");
        $process->run();

        if ($process->isSuccessful()) {
            return true;
        }
        return false;
    }
}
