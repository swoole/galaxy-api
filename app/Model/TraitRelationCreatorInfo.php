<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

trait TraitRelationCreatorInfo
{
    /**
     * 模型关联: creator_info.
     */
    public function creatorInfo()
    {
        return $this->hasOne(User::class, 'id', 'creator')
            ->join('user_profile', 'user.id', '=', 'user_profile.uid')
            ->join('org_member', 'user.id', '=', 'org_member.uid')
            ->select(
                'user.id',
                'user.email',
                'user_profile.avatar',
                'user_profile.nickname',
                'org_member.realname',
                'org_member.workcode'
            );
    }

    /**
     * 直接查询组织用户.
     */
    public function userInfo($uid, $orgId)
    {
        return User::where('user.id', $uid)
            ->where('org_id', $orgId)
            ->join('user_profile', 'user.id', '=', 'user_profile.uid')
            ->join('org_member', 'user.id', '=', 'org_member.uid')
            ->select(
                'user.id',
                'user.email',
                'user_profile.avatar',
                'user_profile.nickname',
                'org_member.realname',
                'org_member.workcode'
            )->first();
    }
}
