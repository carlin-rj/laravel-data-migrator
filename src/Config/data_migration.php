<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Migration Configuration
    |--------------------------------------------------------------------------
    |
    | This option defines the default configuration for data migrations.
    | You can define multiple migration configurations in this array.
    |
    */

    'migrations' => [
        [
            'name' => 'Example Migration',

            // 主表关联关系定义与特定的条件查询
            'tables' => [
                // 示例表配置（不依赖ORM）
                [
                    'table' => 'users',
                    'date_column' => 'created_at', // 日期字段
                    'conditions' => [
                        // 支持多种条件格式 数组或者回调函数
                        ['status', '=', 'active'],
                        ['created_at', '>', '2020-01-01']
                    ],
					'local_key' => 'id',
					// 关联关系定义（支持多层嵌套）
                    'relationships' => [
                        [
                            'table' => 'posts',
                            'local_key' => 'id',
                            'foreign_key' => 'user_id',
                            // 支持深层关联
                            'relationships' => [
                                [
                                    'table' => 'comments',
                                    'local_key' => 'id',
                                    'foreign_key' => 'post_id',
                                ]
                            ]
                        ],
                        [
                            'table' => 'user_profiles',
                            'local_key' => 'id',
                            'foreign_key' => 'user_id',
                        ]
                    ]
                ]
            ],

            // 迁移模式
            // 1 = 按年份迁移
            // 2 = 按月份迁移
            'mode' => 1,

            // 源数据库连接配置（如果不使用默认连接）
            'source_connection' => 'mysql',

            // 目标数据库连接配置
            'target_connection' => 'target',

            // 每批迁移数量
            'chunk' => 500,

            //是否删除目标库的数据
            'delete' => true,

            // 保留最近N年/月的数据
            'retain' => 1,

            // 步进(年/月) - 控制每个表包含多少年/月的数据
            'step' => 1,

            // 年份迁移时目标表名后缀规则
            // 可用占位符: {%table} = 原表名, {%year} = 年份, {%start_year} = 起始年份,
            // {%end_year} = 结束年份, {%month} = 月份, {%start_month} = 起始月份, {%end_month} = 结束月份
            'table_suffix' => '{%table}_{%year}',
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Modes
    |--------------------------------------------------------------------------
    |
    | Define available migration modes
    |
    */

    'modes' => [
        1 => 'yearly',      // 按年份迁移
    ],
];
