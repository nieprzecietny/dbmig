<?php
return [
    'backend' => [
        'frontName' => 'admin_1abc'
    ],
    'crypt' => [
        'key' => '0123456789abcdef'
    ],
    'db' => [
        'table_prefix' => '',
        'connection' => [
            'default' => [
                'host' => 'mysql57',
                'dbname' => 'acc_shop',
                'username' => 'acc_shop',
                'password' => 'shopPass#1',
                'model' => 'mysql4',
                'engine' => 'innodb',
                'initStatements' => 'SET NAMES utf8;',
                'active' => '1',
                'driver_options' => [
                    1014 => false
                ]
            ],
            'indexer' => [
                'host' => 'mysql57',
                'dbname' => 'acc_shop',
                'username' => 'acc_shop',
                'password' => 'shopPass#1',
                'active' => '1',
            ]
        ]
    ],
    'resource' => [
        'default_setup' => [
            'connection' => 'default'
        ]
    ],
    'session' => [
        'save' => 'redis',
        'redis' => [
            'host' => 'redis-host',
            'port' => '6379',
            'password' => 'redis-secret',
            'database' => '2'
        ]
    ],
    'x-frame-options' => 'SAMEORIGIN',
    'MAGE_MODE' => 'production',
];
