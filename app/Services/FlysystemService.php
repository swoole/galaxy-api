<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Stringable\Str;
use League\Flysystem\Filesystem;

class FlysystemService
{
    #[Inject]
    protected Filesystem $filesystem;

    public function getFilesystem()
    {
        return $this->filesystem;
    }

    public function fileExists($filepath): bool
    {
        return $this->filesystem->fileExists($filepath);
    }

    /**
     * 移动临时文件.
     * @param $url
     * @param string $path
     * @param string $prefix
     * @throws \League\Flysystem\FilesystemException
     * @return string
     */
    public function handlerTmpFile($url, bool $move = false, $path = '/uploads/logos/', $prefix = 'tmp/')
    {
        $split = $path . $prefix;

        if (Str::contains($url, $split)) {
            $before_path = Str::replace(Str::before($url, $split), '', $url);
            $url = Str::replace($split, $path, $url);
            $now_path = Str::replace(Str::before($url, $path), '', $url);
            if ($move) {
                $this->filesystem->move($before_path, $now_path);
                return '';
            }
        }

        return $url;
    }
}
