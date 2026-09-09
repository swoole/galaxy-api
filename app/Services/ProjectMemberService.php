<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Constants\ErrorCode;
use App\Constants\Role;
use App\Exception\AppException;
use App\Model\ProjectMember;
use App\Model\OrgMember;

class ProjectMemberService
{
    /**
     * 查看用户是否存在.
     */
    public function existMember(array $params)
    {
        $exist = ProjectMember::where([
            'org_id' => $params['org_id'],
            'group_id' => $params['group_id'],
            'uid' => $params['uid'],
            'project_id' => $params['project_id'],
        ])->first();
        if (! $exist) {
            throw new AppException(
                ErrorCode::PROJECT_MEMBER_NO_EXIST,
                ErrorCode::getMessage(ErrorCode::PROJECT_MEMBER_NO_EXIST)
            );
        }
        return $exist;
    }

    public function getOrgMemberInfo($org_id, $uid)
    {
        $member = OrgMember::where([
            'org_id' => $org_id,
            'uid' => $uid,
        ])->first(['id', 'realname', 'workcode']);
        if (! $member) {
            return [];
        }
        return $member->toArray();
    }

    public function handlerList($data)
    {
        $result = [];
        foreach ($data as $item) {
            $orgMemberInfo = $this->getOrgMemberInfo($item['org_id'], $item['uid']);
            $result[] = [
                'id' => $item['uid'],
                'nickname' => $item['user']['user_info']['nickname'] ?? '',
                'email' => $item['user']['email'] ?? '',
                'avatar' => $item['user']['user_info']['avatar'] ?? '',
                'realname' => $orgMemberInfo['realname'] ?? '',
                'workcode' => $orgMemberInfo['workcode'] ?? '',
                'role' => $item['role'],
                'join_at' => $item['join_at'],
            ];
        }
        return $result;
    }
}
