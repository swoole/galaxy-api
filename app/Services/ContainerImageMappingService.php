<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ContainerImageMapping;

final class ContainerImageMappingService
{
    public function list(int $orgId): array
    {
        $clusterTitles = Cluster::where('org_id', $orgId)->pluck('title', 'id')->all();
        return ContainerImageMapping::where('org_id', $orgId)
            ->orderBy('cluster_id')
            ->orderBy('source_image')
            ->get()
            ->map(static function (ContainerImageMapping $mapping) use ($clusterTitles): array {
                $row = $mapping->toArray();
                $row['scope'] = $mapping->cluster_id > 0 ? 'cluster' : 'global';
                $row['cluster_title'] = $mapping->cluster_id > 0
                    ? (string) ($clusterTitles[$mapping->cluster_id] ?? ('集群 #' . $mapping->cluster_id))
                    : '全部集群';
                return $row;
            })
            ->all();
    }

    public function save(
        int $uid,
        int $orgId,
        int $id,
        int $clusterId,
        string $sourceImage,
        string $targetImage,
        string $remark,
        bool $enabled
    ): ContainerImageMapping {
        $sourceImage = $this->image($sourceImage, '源镜像');
        $targetImage = $this->image($targetImage, '目标镜像');
        if ($sourceImage === $targetImage) {
            throw new AppException(422, '目标镜像不能与源镜像相同');
        }
        if ($clusterId > 0 && ! Cluster::where('org_id', $orgId)->where('id', $clusterId)->exists()) {
            throw new AppException(404, '集群不存在或不属于当前组织');
        }

        $mapping = $id > 0
            ? ContainerImageMapping::where('org_id', $orgId)->where('id', $id)->first()
            : new ContainerImageMapping();
        if ($mapping === null) {
            throw new AppException(404, '镜像映射不存在');
        }
        $duplicate = ContainerImageMapping::where('org_id', $orgId)
            ->where('cluster_id', $clusterId)
            ->where('source_image', $sourceImage);
        if ($id > 0) {
            $duplicate->where('id', '<>', $id);
        }
        if ($duplicate->exists()) {
            throw new AppException(409, '相同作用域下已存在该源镜像映射');
        }

        $now = time();
        $mapping->org_id = $orgId;
        $mapping->cluster_id = $clusterId;
        $mapping->source_image = $sourceImage;
        $mapping->target_image = $targetImage;
        $mapping->remark = mb_substr(trim($remark), 0, 255);
        $mapping->enabled = $enabled ? 1 : 0;
        $mapping->updated_at = $now;
        if (! $mapping->exists) {
            $mapping->creator = $uid;
            $mapping->created_at = $now;
        }
        $mapping->save();
        return $mapping->fresh();
    }

    public function delete(int $orgId, int $id): void
    {
        $deleted = ContainerImageMapping::where('org_id', $orgId)->where('id', $id)->delete();
        if ($deleted < 1) {
            throw new AppException(404, '镜像映射不存在');
        }
    }

    /**
     * Resolve an upstream image immediately before it is sent to an
     * orchestrator. Cluster-specific rules override organization-wide rules.
     */
    public function resolve(Cluster $cluster, string $sourceImage): string
    {
        $sourceImage = $this->image($sourceImage, '源镜像');
        $mapping = ContainerImageMapping::where('org_id', (int) $cluster->org_id)
            ->where('source_image', $sourceImage)
            ->where('enabled', 1)
            ->whereIn('cluster_id', [0, (int) $cluster->id])
            ->orderByDesc('cluster_id')
            ->first();
        return $mapping === null ? $sourceImage : (string) $mapping->target_image;
    }

    private function image(string $image, string $label): string
    {
        $image = trim($image);
        if ($image === '' || strlen($image) > 1024 || preg_match('/[\x00-\x20]/', $image)) {
            throw new AppException(422, $label . '格式不合法');
        }
        return $image;
    }
}

