<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

/**
 * @Constants
 */
class ErrorCode extends AbstractConstants
{
    /**
     * @Message("用户 Token 无效")
     */
    public const TOKEN_INVALID = 401;

    /**
     * @Message("基础参数校验失败")
     */
    public const PARAMS_ERROR = 422;

    /**
     * @Message("参数错误")
     */
    public const INVALID_PARAMS = 100422;

    /**
     * @Message("数据不存在")
     */
    public const NOT_FOUND = 100404;

    /**
     * @Message("token异常")
     */
    public const TOKEN_ERROR = 100000;

    /**
     * @Message("token已过期")
     */
    public const TOKEN_EXPIRED_ERROR = 100001;

    /**
     * @Message("请登录")
     */
    public const NO_LOGIN_ERROR = 100002;

    /**
     * @Message("请重新登录")
     */
    public const RE_LOGIN_ERROR = 100003;

    /**
     * @Message("ID不合法")
     */
    public const HASHID_INVALID = 100004;

    /**
     * @Message("图形验证码已失效,请重新获取")
     */
    public const CAPTCHA_LOSS_EFFICACY_ERROR = 100101;

    /**
     * @Message("图形验证码错误,请重新输入")
     */
    public const CAPTCHA_NOT_EQ_ERROR = 100102;

    /**
     * @Message("邮箱验证码发送失败")
     */
    public const EMAIL_CODE_SEND_ERROR = 100103;

    /**
     * @Message("邮件模板不存在")
     */
    public const EMAIL_CODE_TEMP_ERROR = 100104;

    /**
     * @Message("短信验证码发送失败")
     */
    public const SMS_CODE_SEND_ERROR = 100105;

    /**
     * @Message("短信验证码模板不存在")
     */
    public const SMS_CODE_TEMP_ERROR = 100106;

    /**
     * @Message("短信验证码类型不存在")
     */
    public const SMS_CODE_TYPE_ERROR = 100107;

    /**
     * @Message("发送频繁,%ss 后可再次发送")
     */
    public const SMS_CODE_FREQUENT_ERROR = 100108;

    /**
     * @Message("请获取验证码")
     */
    public const SMS_CODE_NO_EXIST_ERROR = 100109;

    /**
     * @Message("验证码已失效,请重新获取")
     */
    public const SMS_CODE_LOSS_EFFICACY_ERROR = 100110;

    /**
     * @Message("验证码错误,请重新输入")
     */
    public const SMS_CODE_VALID_ERROR = 100111;

    /**
     * @Message("今日短信发送已达上限")
     */
    public const SMS_TODAY_SEND_MAX_ERROR = 100112;

    /**
     * @Message("手机号不合法,请重新输入")
     */
    public const ILLEGAL_PHONE_ERROR = 100113;

    /**
     * @Message("账号必须是手机号或邮箱")
     */
    public const ILLEGAL_EMAIL_ERROR = 100114;

    /**
     * @Message("两次密码不一致")
     */
    public const PASSWORD_CONFIRM_ERROR = 100115;

    /**
     * @Message("ssh密钥生成失败")
     */
    public const SSH_CREATE_DIR_ERROR = 100116;

    /**
     * @Message("ssh密钥生成失败")
     */
    public const SSH_DELETE_DIR_ERROR = 100117;

    /**
     * @Message("ssh密钥生成失败")
     */
    public const SSH_CREATE_ERROR = 100118;

    /**
     * @Message("ssh密钥失败")
     */
    public const SSH_RECORD_ERROR = 100119;

    /**
     * @Message("个人组织创建失败")
     */
    public const SELF_ORG_ERROR = 100120;

    /**
     * @Message("用户注册失败")
     */
    public const REGISTER_ERROR = 100121;

    /**
     * @Message("用户生成失败")
     */
    public const USER_CREATE_ERROR = 100122;

    /**
     * @Message("用户信息生成失败")
     */
    public const USER_PROFILE_CREATE_ERROR = 100123;

    /**
     * @Message("密码错误")
     */
    public const USER_PASSWORD_ERROR = 100124;

    /**
     * @Message("账号不存在")
     */
    public const ACCOUNT_NOT_EXIST_ERROR = 100125;

    /**
     * @Message("重置密码失败")
     */
    public const RESET_PASSWORD_ERROR = 100126;

    /**
     * @Message("新邮箱已被使用")
     */
    public const NEW_EMAIL_IS_USED_ERROR = 100127;

    /**
     * @Message("新手机号已被使用")
     */
    public const NEW_PHONE_IS_USED_ERROR = 100128;


    /**
     * @Message("Git仓库未配置平台密钥")
     */
    public const SSH_NOT_CONFIGURED_FOR_GITREPO = 100130;

    /**
     * @Message("身份验证已失效,请重新验证")
     */
    public const AUTH_LOSS_EFFICACY_ERROR = 100201;

    /**
     * @Message("新邮箱不能与旧邮箱相同")
     */
    public const EMAIL_SAME_ERROR = 100202;

    /**
     * @Message("设置邮箱失败")
     */
    public const SET_EMAIL_ERROR = 100203;

    /**
     * @Message("新手机号不能与旧手机号相同")
     */
    public const PHONE_SAME_ERROR = 100204;

    /**
     * @Message("设置手机号失败")
     */
    public const SET_PHONE_ERROR = 100205;

    /**
     * @Message("账户还未绑定手机号")
     */
    public const PHONE_NOT_BIND_ERROR = 100206;

    /**
     * @Message("账户还未绑定邮箱")
     */
    public const EMAIL_NOT_BIND_ERROR = 100207;

    /**
     * @Message("修改失败")
     */
    public const UPDATE_FAILED = 100501;

    /**
     * @Message("数据不存在")
     */
    public const INVALID_DATA = 100502;

    /**
     * @Message("创建组织重复")
     */
    public const CREATE_DUPLICATE_ORGANIZATION = 100601;

    /**
     * @Message("创建失败")
     */
    public const CREATION_FAILED = 100602;

    /**
     * @Message("同步成员信息失败")
     */
    public const FAILED_TO_ORG_MEMBER = 100603;

    /**
     * @Message("没有权限")
     */
    public const NO_PERMISSION = 100604;

    /**
     * @Message("修改失败")
     */
    public const MODIFICATION_FAILED = 100605;

    /**
     * @Message("数据不存在")
     */
    public const DATA_DOES_NOT_EXIST = 100606;

    /**
     * @Message("owner不可退出组织")
     */
    public const OWNER_CANNOT_QUIT_THE_ORGANIZATION = 100607;

    /**
     * @Message("操作失败")
     */
    public const OPERATION_FAILED = 100608;

    /**
     * @Message("数据异常")
     */
    public const DATA_EXCEPTION = 100609;

    /**
     * @Message("角色不存在")
     */
    public const ROLE_NOT_EXIST = 100610;

    /**
     * @Message("数据重复")
     */
    public const DATA_DUPLICATION = 100701;

    /**
     * @Message("非管理员/负责人没有权限")
     */
    public const TAG_NO_PERMISSION = 100702;

    /**
     * @Message("数据重复")
     */
    public const FAILED_TO_DEL_TAG = 100703;

    /**
     * @Message("未能删除成员和标记关系")
     */
    public const FAILED_TO_DEL_MEMBER_AND_TAG_RELATION = 100704;

    /**
     * @Message("移除失败")
     */
    public const REMOVAL_FAILED = 100705;

    /**
     * @Message("手机或邮箱必填")
     */
    public const PHONE_OR_EMAIL_IS_REQUIRED = 100706;

    /**
     * @Message("手机号和邮箱不匹配")
     */
    public const PHONE_AND_EMAIL_DO_NOT_MATCH = 100707;

    /**
     * @Message("用户不存在")
     */
    public const USER_NOT_EXIST = 100708;

    /**
     * @Message("已在组织内")
     */
    public const ALREADY_IN_ORGANIZATION = 100709;

    /**
     * @Message("邀请失败")
     */
    public const INVITE_FAILED = 100710;

    /**
     * @Message("组织成员标签删除失败")
     */
    public const DELETE_TAGS_FAILED = 100711;

    /**
     * @Message("更新成员信息失败")
     */
    public const FAILED_TO_UPDATE_MEMBER = 100712;

    /**
     * @Message("该用户不在组织内 请核对")
     */
    public const NOT_IN_ORGANIZATION = 100713;

    /**
     * @Message("组织仅能有一名负责人")
     */
    public const ADMIN_IS_ONLY = 100714;

    /**
     * @Message("组织切换异常")
     */
    public const ORG_SWITCH_ERROR = 100715;

    /**
     * @Message("组织不存在")
     */
    public const ORG_NO_EXIST = 100801;

    /**
     * @Message("您还不是组织成员")
     */
    public const ORG_MEMBER_NO_EXIST = 100802;


    /**
     * @Message("项目组不存在")
     */
    public const GROUP_NO_EXIST = 100803;

    /**
     * @Message("您还不是项目组成员")
     */
    public const GROUP_MEMBER_NO_EXIST = 100804;

    /**
     * @Message("项目组名称重复")
     */
    public const GROUP_TITLE_DUPLICATE = 100805;

    /**
     * @Message("项目组创建失败")
     */
    public const GROUP_CREATE_FAILED = 100807;

    /**
     * @Message("项目组成员创建失败")
     */
    public const GROUP_MEMBER_CREATE_FAILED = 100808;

    /**
     * @Message("项目组信息更新失败")
     */
    public const GROUP_UPDATE_FAILED = 100809;

    /**
     * @Message("创建人无法退出项目组")
     */
    public const GROUP_ADMIN_EXIT_FAILED = 100810;

    /**
     * @Message("退出项目组失败")
     */
    public const GROUP_EXIT_FAILED = 100811;

    /**
     * @Message("项目组中存在项目，请勿删除")
     */
    public const GROUP_DELETE_EXIST_PROJECT_FAILED = 100812;

    /**
     * @Message("删除项目组失败")
     */
    public const GROUP_DELETE_FAILED = 100813;

    /**
     * @Message("删除项目组成员失败")
     */
    public const GROUP_DELETE_MEMBER_FAILED = 100814;

    /**
     * @Message("该用户还不是组织成员")
     */
    public const GROUP_CHECK_ORG_MEMBER = 100815;

    /**
     * @Message("该用户已存在项目组中")
     */
    public const GROUP_CHECK_MEMBER = 100816;

    /**
     * @Message("新增项目组成员失败")
     */
    public const GROUP_CREATE_MEMBER = 100817;

    /**
     * @Message("更新项目组成员失败")
     */
    public const GROUP_UPDATE_MEMBER = 100818;

    /**
     * @Message("该用户还不是项目组成员")
     */
    public const GROUP_CHECK_MEMBER_NO_EXIST = 100819;

    /**
     * @Message("删除项目组成员失败")
     */
    public const GROUP_DELETE_MEMBER = 100820;

    /**
     * @Message("项目创建失败")
     */
    public const PROJECT_CREATE_FAILED = 101001;

    /**
     * @Message("项目成员创建失败")
     */
    public const PROJECT_MEMBER_CREATE_FAILED = 101002;

    /**
     * @Message("项目成员不存在")
     */
    public const PROJECT_MEMBER_NO_EXIST = 101003;

    /**
     * @Message("删除项目成员失败")
     */
    public const PROJECT_MEMBER_DELETE_FAILED = 101004;

    /**
     * @Message("项目基础信息修改失败")
     */
    public const PROJECT_UPDATE_FAILED = 101005;

    /**
     * @Message("非该项目成员")
     */
    public const NOT_PROJECT_MEMBER = 101006;

    /**
     * @Message("负责人不可退出项目")
     */
    public const PROJECT_OWNER_CANNOT_EXIT = 101007;

    /**
     * @Message("退出项目失败")
     */
    public const PROJECT_EXIT_FAILED = 101008;

    /**
     * @Message("删除项目失败")
     */
    public const PROJECT_DELETE_FAILED = 101009;

    /**
     * @Message("项目不存在")
     */
    public const PROJECT_NO_EXIST = 101010;

    /**
     * @Message("项目名称重复")
     */
    public const PROJECT_TITLE_DUPLICATE = 101011;

    /**
     * @Message("该用户已存在项目中")
     */
    public const PROJECT_CHECK_MEMBER = 101013;

    /**
     * @Message("环境名称重复")
     */
    public const ENV_TITLE_DUPLICATE = 101101;

    /**
     * @Message("环境创建失败")
     */
    public const ENV_CREATE_FAILED = 101103;

    /**
     * @Message("环境与集群关联失败")
     */
    public const ENV_REL_CREATE_FAILED = 101104;

    /**
     * @Message("环境更新失败")
     */
    public const ENV_UPDATE_FAILED = 101105;

    /**
     * @Message("该环境关联集群，不可删除")
     */
    public const ENV_CANNOT_DELETE = 101106;

    /**
     * @Message("该环境已配置环境变量，请先删除环境变量")
     */
    public const CANNOT_DELETE_BY_PROJECT_ENV = 101111;

    /**
     * @Message("删除失败")
     */
    public const ENV_DELETE_FAILED = 101107;

    /**
     * @Message("环境不存在")
     */
    public const ENV_NO_EXIST = 101108;

    /**
     * @Message("环境关联集群删除失败")
     */
    public const ENV_CLUSTER_REL_DELETE_FAILED = 101109;

    /**
     * @Message("环境关联集群修改失败")
     */
    public const ENV_CLUSTER_REL_UPDATE_FAILED = 101110;

    /**
     * @Message("环境变量添加失败")
     */
    public const PROJECT_ENV_CREATE_FAILED = 101201;

    /**
     * @Message("环境变量修改失败/环境变量没有改变")
     */
    public const PROJECT_ENV_UPDATE_FAILED = 101202;

    /**
     * @Message("环境变量不存在")
     */
    public const PROJECT_ENV_NO_EXIST = 101203;

    /**
     * @Message("环境变量KEY格式错误，仅允许数字字母下划线")
     */
    public const ENV_KEY_FORMAT_ERROR = 101204;

    /**
     * @Message("环境变量KEY重复")
     */
    public const KEY_IS_REPEAT = 101205;

    /**
     * @Message("git钩子状态修改失败")
     */
    public const GIT_HOOK_STATUS_UPDATE_FAILED = 101206;

    /**
     * @Message("集群不存在")
     */
    public const CLUSTER_NO_EXIST = 103001;

    /**
     * @Message("集群创建失败")
     */
    public const CLUSTER_CREATE_FAILED = 103002;

    /**
     * @Message("集群信息修改失败")
     */
    public const CLUSTER_UPDATE_FAILED = 103003;

    /**
     * @Message("参数错误")
     */
    public const PARAMETER_ERROR = 103004;

    /**
     * @Message("集群名称重复")
     */
    public const CLUSTER_NAME_DUPLICATION = 103005;

    /**
     * @Message("对不起,您没有权限操作流水线")
     */
    public const PIPELINE_NO_PERMISSION = 104001;

    /**
     * @Message("流水线创建失败")
     */
    public const PIPELINE_CREATE_ERROR = 104002;

    /**
     * @Message("流水线不存在")
     */
    public const PIPELINE_NO_EXIST = 104003;

    /**
     * @Message("流水线已存在")
     */
    public const PIPELINE_IS_EXIST = 104004;

    /**
     * @Message("流水线名称仅支持 数字/小写字母/-/. ")
     */
    public const PIPELINE_TITLE_PREG = 104005;

    /**
     * @Message("流水线更新失败")
     */
    public const PIPELINE_UPDATE_ERROR = 104006;

    /**
     * @Message("流水线删除失败")
     */
    public const PIPELINE_DELETE_ERROR = 104007;

    /**
     * @Message("流水线项目关联配置创建失败")
     */
    public const PIPELINE_PROJECT_REL_CREATE_ERROR = 104008;

    /**
     * @Message("流水线项目关联配置不存在")
     */
    public const PIPELINE_PROJECT_REL_NO_EXIST = 104009;

    /**
     * @Message("流水线项目关联配置删除失败")
     */
    public const PIPELINE_PROJECT_REL_DELETE_ERROR = 104010;

    /**
     * @Message("不支持的 HOOK 类型")
     */
    public const WEBHOOK_NO_SUPPORT_TYPE = 106001;

    /**
     * @Message("未配置该仓库信息")
     */
    public const WEBHOOK_REPO_NO_EXIST = 106002;

    /**
     * @Message("未配置流水线")
     */
    public const WEBHOOK_NO_PIPELINE = 106003;

    /**
     * @Message("Service不存在")
     */
    public const SERVICE_NO_EXIST = 106101;

    /**
     * @Message("Ingress不存在")
     */
    public const INGRESS_NO_EXIST = 106201;

    /**
     * @Message("不能删除使用中的模版")
     */
    public const CANNOT_DELETE_ACTIVATED_HPA = 106202;

    /**
     * @Message("进度任务不存在")
     */
    public const PROGRESS_NOT_FOUND = 106203;

    /**
     * @Message("已存在此registry配置")
     */
    public const REGISTRY_DUPLICATION = 106300;

    /**
     * @Message("registry帐号密码错误")
     */
    public const REGISTRY_WRONG_PASSWORD = 106301;

    /**
     * @Message("registry地址不可用")
     */
    public const REGISTRY_INVALID_ADDRESS = 106302;

    /**
     * @Message("通过镜像名称匹配不到registry帐号")
     */
    public const REGISTRY_MISMATCH_BY_NAME = 106303;

    /**
     * @Message("获取镜像推送帐号失败")
     */
    public const REGISTRY_NOT_FOUND_PUSH = 106304;

    /**
     * @Message("计算镜像大小失败")
     */
    public const REGISTRY_CALCU_IMAGE_SIZE_FAILED = 106305;

    /**
     * @Message("未设置命名空间，不能作为推送镜像仓库")
     */
    public const REGISTRY_EMPTY_NAMESPACE_AS_PUSH = 106306;

    /**
     * @Message("镜像仓库不存在，不能作为项目镜像仓库")
     */
    public const REGISTRY_NOT_FOUND_FOR_PROJECT = 106307;

    /**
     * @Message("集群组件未安装导致API报错")
     */
    public const CLUSTER_COMPONENT_NOT_INSTALL_API_ERROR = 106400;

    /**
     * @Message("Location冲突")
     */
    public const DOMAIN_LOCATION_CONFLICT = 107001;

    /**
     * @Message("不支持该证书续期")
     */
    public const DOMAIN_SSL_NOT_SUPPORT_RENEWAL = 107002;

    /**
     * @Message("未找到指定的版本")
     */
    public const CLI_FIND_VERSION_FAIL = 110001;

    /**
     * @Message("登录次数超限,请稍后重试")
     */
    public const CLI_LOGIN_INC_FAIL = 110002;

}
