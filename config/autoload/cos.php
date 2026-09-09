<?php
return [
    'mysql_backup' => [
        'region' => env('COS_REGION', 'ap-shanghai'),
        'credentials' => [
            'appId' => env('COS_APPID'),
            'secretId' => env('COS_SECRET_ID'),
            'secretKey' => env('COS_SECRET_KEY'),
            'token' => env('QCLOUD_COSV5_TOKEN'),
        ],
        'timeout' => env('QCLOUD_COSV5_TIMEOUT', 5),
        'connect_timeout' => env('QCLOUD_COSV5_CONNECT_TIMEOUT', 5),
        'bucket' => env('COS_BUCKET_MYSQL_BACKUP', 'galaxy-mysql-backup-1252906962'),
        'cdn' => env('QCLOUD_COSV5_CDN'),
        'scheme' => env('QCLOUD_COSV5_SCHEME', 'https'),
        'read_from_cdn' => env('QCLOUD_COSV5_READ_FROM_CDN', false),
        'cdn_key' => env('QCLOUD_COSV5_CDN_KEY'),
        'encrypt' => env('QCLOUD_COSV5_ENCRYPT', false),
    ],
];
