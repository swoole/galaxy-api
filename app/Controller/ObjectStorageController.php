<?php

namespace App\Controller;

use App\Services\ObjectStorageService;
use App\Support\Functions;

class ObjectStorageController extends AbstractController
{
    public function __construct(private ObjectStorageService $storage) {}

    // GET /resources/storage-buckets
    public function listBuckets()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = Functions::arrNull2default($this->validate([
            'keyword' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]), ['keyword' => null, 'page' => 1, 'pagesize' => 20]);

        return $this->success($this->storage->listBuckets(
            $orgId, $params['keyword'], (int) $params['page'], (int) $params['pagesize']
        ));
    }

    // GET /resources/storage-buckets/discover
    public function discoverBuckets()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $params = $this->validate([
            'cloud_account_id' => 'required|integer|min:1',
            'region' => 'nullable|string|max:64',
            'endpoint' => 'nullable|string|max:255',
        ]);
        return $this->success(['buckets' => $this->storage->discoverBuckets(
            $orgId,
            (int) $params['cloud_account_id'],
            trim((string) ($params['region'] ?? '')),
            trim((string) ($params['endpoint'] ?? ''))
        )]);
    }

    // POST /resources/storage-buckets
    public function createBucket()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'title' => 'required|string|max:120',
            'cloud_account_id' => 'required|integer|min:1',
            'config' => 'nullable|array',
            'bucket' => 'required|string|max:255',
            'region' => 'nullable|string|max:64',
            'endpoint' => 'nullable|string|max:255',
            'base_url' => 'nullable|string|max:512',
        ]);

        return $this->success([
            'bucket' => $this->storage->createBucket(
                (int) Functions::getLoginUser()->getId(), $orgId, $params
            ),
        ]);
    }

    // GET /resources/storage-buckets/{id}
    public function showBucket(int $id)
    {
        $orgId = Functions::getContextValue('org_id');
        return $this->success([
            'bucket' => $this->storage->getBucket($orgId, $id),
        ]);
    }

    // PUT /resources/storage-buckets/{id}
    public function updateBucket(int $id)
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'title' => 'nullable|string|max:120',
            'config' => 'nullable|array',
            'cloud_account_id' => 'nullable|integer|min:1',
            'bucket' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:64',
            'endpoint' => 'nullable|string|max:255',
            'base_url' => 'nullable|string|max:512',
        ]);
        $data = array_filter($params, fn($v) => $v !== null);

        return $this->success([
            'bucket' => $this->storage->updateBucket($orgId, $id, $data),
        ]);
    }

    // DELETE /resources/storage-buckets/{id}
    public function deleteBucket(int $id)
    {
        $orgId = Functions::getContextValue('org_id');
        $this->storage->deleteBucket($orgId, $id);
        return $this->success();
    }

    // POST /resources/storage-buckets/{id}/default
    public function setDefault(int $id)
    {
        $orgId = Functions::getContextValue('org_id');
        $this->storage->setDefault($orgId, $id);
        return $this->success();
    }

    // GET /resources/storage-files
    public function listFiles()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = Functions::arrNull2default($this->validate([
            'bucket_id' => 'required|integer|min:1',
            'prefix' => 'nullable|string|max:1024',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
            'cursor' => 'nullable|string|max:4096',
        ]), ['prefix' => '', 'page' => 1, 'pagesize' => 50, 'cursor' => '']);

        return $this->success($this->storage->listFiles(
            $orgId, (int) $params['bucket_id'],
            $params['prefix'] ?? '',
            (int) $params['page'], (int) $params['pagesize'], (string) ($params['cursor'] ?? '')
        ));
    }

    // GET /resources/storage-files/download
    public function downloadFile()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $params = $this->validate([
            'bucket_id' => 'required|integer|min:1',
            'path' => 'required|string|max:1024',
        ]);
        $file = $this->storage->download($orgId, (int) $params['bucket_id'], (string) $params['path']);

        return $this->downloadContent($file['content'], $file['filename'], $file['mime_type']);
    }

    // POST /resources/storage-files/upload
    public function uploadFile()
    {
        $orgId = Functions::getContextValue('org_id');
        $file = $this->request->file('file');
        $params = $this->validateAll([
            'bucket_id' => 'required|integer',
            'dir' => 'nullable|string|max:512',
            'file' => 'required|file',
        ], [], array_merge($this->request->all(), ['file' => $file]));

        return $this->success([
            'file' => $this->storage->upload($orgId, (int) $params['bucket_id'], $file, $params['dir'] ?? ''),
        ]);
    }

    // POST /resources/storage-directories
    public function createDirectory()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $params = $this->validate([
            'bucket_id' => 'required|integer|min:1',
            'parent' => 'nullable|string|max:1024',
            'name' => 'required|string|max:255',
        ]);
        return $this->success(['directory' => $this->storage->createDirectory(
            $orgId,
            (int) $params['bucket_id'],
            (string) ($params['parent'] ?? ''),
            (string) $params['name']
        )]);
    }

    // DELETE /resources/storage-files  (body: bucket_id, path)
    public function deleteFile()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'bucket_id' => 'required|integer',
            'path' => 'required|string|max:1024',
            'type' => 'nullable|string|in:file,dir',
        ]);
        $this->storage->deleteEntry(
            $orgId,
            (int) $params['bucket_id'],
            $params['path'],
            (string) ($params['type'] ?? 'file')
        );
        return $this->success();
    }
}
