<?php

declare(strict_types=1);

return [
    'auth_code_expire_time' => (int) env('OAUTH2_AUTH_CODE_EXPIRE_TIME', 300), // auth code过期时间，单位秒
    'access_token_expire_time' => (int) env('OAUTH2_ACCESS_TOKEN_EXPIRE_TIME', 7200),
    'refresh_token_expire_time' => (int) env('OAUTH2_REFRESH_TOKEN_EXPIRE_TIME', 604800),
    'token_secret' => env('OAUTH2_SECRET', 'jdfHhJIg+Xe23CTabmHjiW2PyEQtrrDPWr+4J0cJ7K0'),
];
