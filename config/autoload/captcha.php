<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
return [
    'length' => env('CAPTCHA_LENGTH', 6), //验证码字符长度
    'fontSize' => env('CAPTCHA_FONT_SIZE', 32), //字体大小
    'imageWidth' => env('CAPTCHA_IMAGE_WIDTH', 250), //图片宽度
    'imageHeight' => env('CAPTCHA_IMAGE_HEIGHT', 80), //图片高度
    'useCurve' => env('CAPTCHA_USE_CURVE', false), //是否开启曲线
    'useNoise' => env('CAPTCHA_USE_NOISE', true), //是否开启噪点
    'useFont' => env('CAPTCHA_USE_FONT', 3), //字体
    'charset' => env('CAPTCHA_CHARSET', '23456789AaBbCcDdEeFfGgHhJjKkMmNnPpQqRrSsTtUuVvWwXxYyZz'),
];
