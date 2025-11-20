# YC Laravel Data Migrator

一个用于在不同数据库之间迁移数据的 Laravel 扩展包，支持按配置定义的主表及其关联关系进行安全、高效的分表迁移。适用于大数据量场景，提供分批迁移、自动建表、数据校验与迁移后清理等功能。

- 包名：`carlin/laravel-data-migrator`
- 服务提供者：`DataMigratorServiceProvider`
- 命令：`MigrateDataCommand`
- 配置文件：`data_migration.php`

## 功能特性
- 基于配置的主表与关联表迁移（不依赖 ORM）
- 支持“按年”分表迁移（配置 `mode=1`）
- 支持步进分组 `step`（如每2年一表：`2021_2022`）
- 自动创建目标库缺失的表结构（克隆源表结构）
- 分批迁移（`chunk`），适配大数据量
- 迁移后数据校验；可选清理源数据（`delete`）
- 关联表与主表保持相同的时间分片与命名规则（基于主表的目标表规则）

## 环境要求
- PHP `^8.0`
- Laravel 组件：`illuminate/support`、`illuminate/database`（兼容 8/9/10）

## 安装
```bash
composer require carlin/laravel-data-migrator
```

## 发布配置
将包内默认配置发布到应用：
```bash
php artisan vendor:publish --provider="YC\DataMigrator\DataMigratorServiceProvider" --tag=config

```
会生成 `config/data_migration.php`。

## 配置说明（`config/data_migration.php`）
示例：
```php
<?php

return [
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

```

## 使用方法
执行迁移命令，指定配置名：
```bash
php artisan data:migrate --name="Example Migration"
```
命令行为由 `MigrateDataCommand` 定义，支持：
- `--name`：选择要执行的迁移配置（来自 `data_migration.php` 的 `migrations[*].name`）

迁移流程由 `DataMigrator` 统一协调：
1. 预处理分片（基于主表一次性生成时间段并缓存）
2. 自动建表（目标库不存在则克隆源表结构）
3. 分批迁移（`chunk`）
4. 校验（按主表分片，关联表基于外键对齐校验）
5. 可选清理源数据（`delete`）

## 校验与安全
- 主表校验：对比源库分片范围的行数与目标分表的行数
- 关联表校验（建议）：基于外键与主表已迁移 ID 做分批对齐统计，避免依赖关联表时间字段；可结合抽样或分批策略提升性能与可靠性
- 迁移过程中建议开启事务分批和失败重试，确保一致性

## 性能建议
- 合理设置 `chunk`（如 500~2000）
- 使用 `retain` 控制仅迁移历史数据，减少分片数量
- 使用 `step` 聚合分片，降低分表数量（如两年一表）
- 对外键与查询条件相关字段建立索引

## 版本兼容
- PHP `^8.0`
- Laravel 8/9/10（`illuminate/*` 组件）

## License
MIT
