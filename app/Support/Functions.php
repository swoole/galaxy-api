<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Support;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Exception\UnauthorizedException;
use App\Model\Project;
use App\Model\Org;
use App\Model\Group;
use Hashids\Hashids;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Snowflake\IdGeneratorInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class Functions
{
    protected static $convert64Pools = [
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v',
        'w', 'x', 'y', 'z',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V',
        'W', 'X', 'Y', 'Z',
         '_', '-',
    ];

    /**
     * @var Hashids
     */
    protected static $hash;

    /**
     * @var array
     */
    protected static $contextValueHandlers = [
        'org_id' => Org::class,
        'group_id' => Group::class,
        'project_id' => Project::class,
    ];

    /**
     * 数据库作用域字段与公网 API 参数的映射.
     */
    protected static $contextPublicNames = [
        'org_id' => 'org',
        'group_id' => 'group',
        'project_id' => 'project',
    ];

    /**
     * 列表转Tree.
     * @param string $pk
     * @param string $pid
     * @param string $child
     */
    public static function list2tree(array $list, $pk = 'id', $pid = 'pid', $child = '_child', $root = 0): array
    {
        // 创建Tree
        $tree = [];
        // 创建基于主键的数组引用
        $refer = [];
        foreach ($list as $key => $data) {
            $refer[$data[$pk]] = &$list[$key];
        }
        foreach ($list as $key => $data) {
            $parentId = $data[$pid];
            if ($root == $parentId) {
                $tree[] = &$list[$key];
            } else {
                if (isset($refer[$parentId])) {
                    $parent = &$refer[$parentId];
                    $parent[$child][] = &$list[$key];
                }
            }
        }

        return $tree;
    }

    /**
     * 将null的数组转为配置的默认值.
     *
     * @param array $data 原始数据，关联数组
     * @param array $defaults 默认值配置，['param1', 'param2']
     *                        或['param1' => 'default1', 'param2' => 'default2']
     * @return array
     */
    public static function arrNull2default($data, $defaults)
    {
        foreach ($defaults as $idx => $val) {
            if (is_numeric($idx)) {
                // 索引数组，默认值为空字符串，字段名为$val
                if (! isset($data[$val]) || is_null($data[$val])) {
                    $data[$val] = '';
                }
            } else {
                // 关联数组，默认值为$val，字段名为$idx
                if (! isset($data[$idx]) || is_null($data[$idx]) || $data[$idx] === '') {
                    $data[$idx] = $val;
                }
            }
        }

        return $data;
    }

    /**
     * 将null的数组转为配置的默认值.
     *
     * @param array $array 原始数据，关联数组
     * @param array $onlyKeys 需要导出的keys，请保证keys务必存在，否则会报语法错误
     * @return array
     */
    public static function arrayOnlyKeys($array, $onlyKeys = [])
    {
        $export = [];
        foreach ($onlyKeys as $key) {
            isset($array[$key]) && $export[$key] = $array[$key];
        }

        return $export;
    }

    /**
     * trim 字符串.
     * 类似 php 函数 trim.
     *
     * @param string $str 源字符
     * @param string $list 待清除字符
     * @return string
     */
    public static function strTrim($str, $list = '')
    {
        $list = (string) $list;
        if (! isset($list[0])) {
            return trim($str);
        }

        $len1 = strlen($str);
        $len2 = strlen($list);
        if ($len2 > $len1) {
            return trim($str);
        }

        $str = static::strLtrim($str, $list);
        return static::strRtrim($str, $list);
    }

    /**
     * ltrim 字符串.
     * 类似 php 函数 ltrim.
     *
     * @param string $str 源字符
     * @param string $list 待清除字符
     * @return string
     */
    public static function strLtrim($str, $list = '')
    {
        $list = (string) $list;
        if (! isset($list[0])) {
            return ltrim($str);
        }

        $len1 = strlen($str);
        $len2 = strlen($list);
        if ($len2 > $len1) {
            return ltrim($str);
        }

        $s = '';
        do {
            $s = substr($str, 0, $len2);
            if ($s == $list) {
                $str = substr($str, $len2);
            }
        } while ($s == $list);

        return $str;
    }

    /**
     * rtrim 字符串.
     * 类似 php 函数 rtrim.
     *
     * @param string $str 源字符
     * @param string $list 待清除字符
     * @return string
     */
    public static function strRtrim($str, $list = '')
    {
        $list = (string) $list;
        if (! isset($list[0])) {
            return rtrim($str);
        }

        $len1 = strlen($str);
        $len2 = strlen($list);
        if ($len2 > $len1) {
            return rtrim($str);
        }

        $s = '';
        do {
            $s = substr($str, -$len2);
            if ($s == $list) {
                $str = substr($str, 0, -$len2);
            }
        } while ($s == $list);

        return $str;
    }

    /**
     * 生成验证码
     * @return int
     */
    public static function createSmsCode()
    {
        return rand(100000, 999999);
    }

    /**
     * 生成雪花id.
     * @return int
     */
    public static function createSnowflakeId()
    {
        $snowflake = container()->get(IdGeneratorInterface::class);
        return $snowflake->generate();
    }

    /**
     * 获取登录用户.
     * @return null|\Qbhy\HyperfAuth\Authenticatable
     */
    public static function getLoginUser()
    {
        $user = auth()->user();
        if (! $user) {
            throw new UnauthorizedException();
        }
        return $user;
    }

    /**
     * 获取Context字段.
     */
    public static function getContextValue($field, $required = true, $default = null)
    {
        if (!Context::has($field)) {
            if (isset(static::$contextValueHandlers[$field])) {
                static::$contextValueHandlers[$field]::getAliasId();
            }
        }

        $value = Context::get($field, $default);
        if ($value === $default && $required) {
            throw new AppException(
                ErrorCode::INVALID_PARAMS,
                sprintf('the parameter %s is required', static::$contextPublicNames[$field] ?? $field)
            );
        }

        return $value;
    }

    /**
     * 简易密码生成.
     * @param array $lens [小写字母长度, 大小字母长度, 数字长度, 符号长度]
     * @param string $sign 符号
     * @return string
     */
    public static function genPassword(array $lens = [8, 8, 4], ?string $sign = null)
    {
        $pools = [
            'abcdefghijklmnopqrstuvwxyz',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789',
            $sign,
        ];

        $chars = [];
        foreach ($lens as $index => $len) {
            $poolLen = strlen($pools[$index]);
            while ($len-- > 0) {
                $chars[] = $pools[$index][mt_rand(0, $poolLen - 1)];
            }
        }
        shuffle($chars);

        return implode('', $chars);
    }

    public static function exceptionFormatter(Throwable $e, ?string $title = null): string
    {
        return sprintf(
            "%s%s:%s(%s) in %s:%s\nStack trace:\n%s",
            $title,
            get_class($e),
            $e->getMessage(),
            $e->getCode(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    public static function exceptionContext(Throwable $e, ?string $title = null): array
    {
        return [
            'title' => $title,
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
    }

    /**
     * 十进制数转其他进制.
     * @param int $num 要转换的十进制数字
     * @param int $to 要转换的进制
     * @return string
     */
    public static function convert(int $num, int $to = 64)
    {
        $output = '';
        while ($num != 0) {
            $mod = $num % $to;
            $output = static::$convert64Pools[$mod] . $output;
            $num = (int) ($num - $mod) / $to;
        }

        return $output;
    }

    /**
     * 从数组中获取数据.
     * @param array $array 原始数组
     * @param array $keys key
     * @param mixed $default 默认值
     * @return array [bool $exists, mixed $value]
     */
    public static function getValue(array $array, array $keys, $default = null) : array
    {
        if (empty($keys)) {
            return [false, $default];
        }

        $subKeyArray = $array;
        foreach ($keys as $segment) {
            if (is_array($subKeyArray) && array_key_exists($segment, $subKeyArray)) {
                $subKeyArray = $subKeyArray[$segment];
            } else {
                return [false, $default];
            }
        }

        return [true, $subKeyArray];
    }

    /**
     * 条件字段拆分
     * 只负责拆分，不负责字段中可能包含的非法字符串检测.
     * a.b.c => ['a', 'b', 'c']
     * [a.b].c => ['a.b', 'c']
     * a.[b.c] => ['a', 'b.c']
     */
    public static function fieldSplit(string $field) : array
    {
        // 拆分/占位规则正则
        $regex = '/(?:^|(?<=\.))(\[(.*?)\])(?:(?=\.)|$)/';
        // 正则占位符
        $placeholder = '$#$';

        // 匹配规则及offset，预定义避免后面报错
        $matches = [];
        $matchOffset = 0;
        if (preg_match_all($regex, $field, $matches, PREG_SET_ORDER)) {
            $field = preg_replace($regex, $placeholder, $field);
        }

        $parts = [];
        foreach (explode('.', $field) as $part) {
            // 当前字符串为占位符且存在匹配的match，则从matches中取值
            // 此处可能存在极端情况占位符被原先就在字段中存在，此处忽略这种极端情况
            if ($part == $placeholder && isset($matches[$matchOffset])) {
                $parts[] = $matches[$matchOffset++][2];
            } else {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * 替换value值.
     */
    public static function replaceValue($vars, $value)
    {
        if (! preg_match_all('/(?<=\{)[^\{\}]+(?=\})/', $value, $matches)) {
            return $value;
        }

        $search = [];
        $replace = [];
        foreach ($matches[0] as $matched) {
            $keys = Functions::fieldSplit($matched);
            [$exists, $replacedValue] = Functions::getValue($vars, $keys, null);
            if ($exists) {
                $search[] = '{' . $matched . '}';
                $replace[] = $replacedValue;
            }
        }

        return str_replace($search, $replace, $value);
    }

    /**
     * 字符串隐藏.
     */
    public static function strHidden(string $str, int $before = 5, int $after = 5, string $replace = '*')
    {
        $length = mb_strlen($str) - $before - $after;

        if ($length <= 0) {
            $length = mb_strlen($str) - 2 <= 0 ? 1 : mb_strlen($str) - 2;
            $before = 1;
        }

        return mb_substr($str, 0, $before) . str_repeat($replace, $length) . mb_substr($str, $before + $length);
    }

    /**
     * 计算SSH公钥sha256指纹.
     */
    public static function calcuSshKeySha256Fingerprint(string $pubkey)
    {
        $content = explode(' ', $pubkey, 3);

        $fingerprint = base64_encode(hash('sha256', base64_decode($content[1]), true));

        return sprintf('SHA256:%s', Functions::strRtrim($fingerprint, '='));
    }

    /**
     * 验证SSH公钥合法性.
     */
    public static function validSshPubKey(string $pubkey)
    {
        $content = explode(' ', $pubkey, 3);
        if (count($content) != 3) {
            return false;
        }

        $algorithm = $content[0];
        $key = $content[1];

        $decoded = base64_decode($key, true);
        if (!$decoded) {
            return false;
        }

        $check = base64_decode($key);
        if (!preg_match('/^.*?([\w\-]+)/', $check, $matches)) {
            return false;
        }

        if ((string) $matches[1] !== (string) $algorithm) {
            return false;
        }

        return true;
    }

    /**
     * 解析命令.
     */
    public static function parseCmd(string $input): array
    {
        $REGEX_STRING = '([^\s]+?)(?:\s|(?<!\\\\)"|(?<!\\\\)\'|$)';
        $REGEX_QUOTED_STRING = '(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\')';
        $tokens = [];
        $length = \strlen($input);
        $cursor = 0;
        while ($cursor < $length) {
            if (preg_match('/\s+/A', $input, $match, 0, $cursor)) {
            } elseif (preg_match('/([^="\'\s]+?)(=?)(' . $REGEX_QUOTED_STRING . '+)/A', $input, $match, 0, $cursor)) {
                $tokens[] = $match[1] . $match[2] . stripcslashes(str_replace(['"\'', '\'"', '\'\'', '""'], '', substr($match[3], 1, -1)));
            } elseif (preg_match('/' . $REGEX_QUOTED_STRING . '/A', $input, $match, 0, $cursor)) {
                $tokens[] = stripcslashes(substr($match[0], 1, -1));
            } elseif (preg_match('/' . $REGEX_STRING . '/A', $input, $match, 0, $cursor)) {
                $tokens[] = stripcslashes($match[1]);
            } else {
                // should never happen
                throw new InvalidArgumentException(
                    sprintf('Unable to parse input near "... %s ...".', substr($input, $cursor, 10))
                );
            }

            $cursor += \strlen($match[0]);
        }

        return $tokens;
    }

    /**
     * 格式化时间差.
     */
    public static function formatTimeDiff($diff, $length = 2) {
        if (empty($diff)) {
            return '-';
        }

        $map = [
            [ 'sec' => 31536000, 'note' => '年' ],
            [ 'sec' => 2592000, 'note' => '月' ],
            [ 'sec' => 86400, 'note' => '天' ],
            [ 'sec' => 3600, 'note' => '小时' ],
            [ 'sec' => 60, 'note' => '分' ],
            [ 'sec' => 1, 'note' => '秒' ]
        ];

        $note = '';
        foreach ($map as $item) {
            if ($diff >= $item['sec']) {
                $length--;
                $note .= sprintf('%s%s', floor($diff / $item['sec']), $item['note']);
                $diff = $diff % $item['sec'];
                if ($length == 0) {
                    break;
                }
            }
        }

        return $note;
    }

    /**
     * 格式化与当前时间差.
     */
    public static function formatTimeDiffNow($timestamp, $length = 2)
    {
        return self::formatTimeDiff(time() - $timestamp, $length);
    }

    public static function formatBytes($size, $fixedLength = 2, $units = null)
    {
        if ($units === null) {
            $units = ['B', 'K', 'M', 'G', 'T'];
        }
        $index = 0;
        if (!$size && $size !== 0) {
            return '-' . $units[$index];
        }

        $sign = 1;
        if ($size < 0) {
            $size *= -1;
            $sign *= -1;
        }

        while ($size >= 1024) {
            $size /= 1024;
            $index++;
        }

        return (round($size, $fixedLength) * $sign). $units[$index];
    }

    /**
     * 生成Service可用序列号.
     */
    public static function genServiceSerial($length = 6)
    {
        $serial = Functions::convert(intval(microtime(true) * 1000 / mt_rand(1, 50000)), 36);

        $len = strlen($serial);
        if ($len == $length) {
            return $serial;
        } elseif ($len > $length) {
            return substr($serial, 0, $length);
        } else {
            $pool = array_merge(range('a', 'z'), range(0, 9));
            $poolLen = count($pool);
            while ($len < $length) {
                $serial = $pool[mt_rand(0, $poolLen - 1)] . $serial;
                $len++;
            }
        }

        return $serial;
    }

    /**
     * 生成序列号.
     */
    public static function genSerial($length = 6)
    {
        $serial = Functions::convert(intval(microtime(true) * 1000 / mt_rand(1, 50000)), 62);

        $len = strlen($serial);
        if ($len == $length) {
            return $serial;
        } elseif ($len > $length) {
            return substr($serial, 0, $length);
        } else {
            $pool = array_merge(range('a', 'z'), range('A', 'Z'), range(0, 9));
            $poolLen = count($pool);
            while ($len < $length) {
                $serial = $pool[mt_rand(0, $poolLen - 1)] . $serial;
                $len++;
            }
        }

        return $serial;
    }

    /**
     * 验证参数.
     */
    public static function validate(array $params, array $rules, array $messages = [])
    {
        /** @var ValidatorInterface */
        $validator = ApplicationContext::getContainer()->get(ValidatorFactoryInterface::class)
            ->make($params, $rules, $messages);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * 合并非空数据.
     */
    public static function mergeNotNull(array $entries, array $array = [])
    {
        foreach ($entries as $key => $value) {
            if (!empty($value)) {
                $array[$key] = $value;
            }
        }

        return $array;
    }

    /**
     * 合并URL参数.
     */
    public static function mergeUrl($baseUrl, $queries = [])
    {
        $baseQuery = parse_url($baseUrl, PHP_URL_QUERY);
        if (!empty($baseQuery)) {
            foreach (explode('&', $baseQuery) as $item) {
                $parts = explode('=', $item);
                if (empty($parts[0]) || isset($queries[$parts[0]])) {
                    continue;
                }
                $queries[$parts[0]] = urldecode($parts[1] ?? '');
            }
        }
        $baseUrl = explode('?', $baseUrl)[0];

        return $baseUrl . '?' . http_build_query($queries);
    }

    /**
     * 初始化hash.
     */
    public static function initHash() : Hashids
    {
        if (is_null(static::$hash)) {
            static::$hash = new Hashids(config('app.hashid.salt'), config('app.hashid.minlength'), config('app.hashid.pool'));
        }

        return static::$hash;
    }

    /**
     * id hash.
     */
    public static function encodeID()
    {
        return static::initHash()->encode(func_get_args());
    }

    /**
     * id hash decode.
     */
    public static function decodeID($hash, $integer = true, $throw = true)
    {
        $ids = static::initHash()->decode($hash);

        if ($integer) {
            // 不抛出异常解析失败时返回null
            if (!$throw) {
                return $ids[0] ?? null;
            }

            // 解析失败时抛出异常
            if (isset($ids[0])) {
                return $ids[0];
            }
            throw new AppException(ErrorCode::HASHID_INVALID, 'ID不合法');
        }

        return $ids;
    }
}
