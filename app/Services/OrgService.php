<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectMember;
use App\Model\Org;
use App\Model\OrgMember;
use App\Model\Group;
use Hyperf\Di\Annotation\Inject;

class OrgService
{
    /**
     * 项目名称 组织/项目内唯一
     * @param $title
     * @param $org_id
     * @param mixed $value
     * @param mixed $group_id
     */
    public function uniqueTitle($value, $org_id, $group_id)
    {
        $existTitle = Project::where(['title' => $value, 'org_id' => $org_id, 'group_id' => $group_id])->exists();
        if ($existTitle) {
            throw new AppException(ErrorCode::GROUP_TITLE_DUPLICATE, ErrorCode::getMessage(ErrorCode::GROUP_TITLE_DUPLICATE));
        }
    }

    public function createWorkcode($org_id)
    {
        $org = Org::where(['id' => $org_id,])->first();
        $workcode = $org->next_workcode + 1;
        $org->next_workcode += 1;
        $org->save();
        return $workcode;
    }

}
