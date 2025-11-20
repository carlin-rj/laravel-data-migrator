# YC Laravel Data Migrator

一个用于在不同数据库之间迁移数据的 Laravel 扩展包，支持按配置定义的主表及其关联关系进行安全、高效的分表迁移。适用于大数据量场景，提供分批迁移、自动建表、数据校验与迁移后清理等功能。

- 包名：`yc/laravel-data-migrator`
- 服务提供者：`DataMigratorServiceProvider`
- 命令：`MigrateDataCommand`
- 配置文件：`data_migration.php`

## 功能特性
- 基于配置的主表与关联表迁移（不依赖 ORM）
- 支持“按年”分表迁移（配置 `mode=1`）
- 支持步进分组 `step`（如每2年一表：`2021_2022`）
- 自动创建目标库缺失的表结构（克隆源表结构）
- 分批迁移（`chunk`），适配大数据量
- 迁移后数据校验；可选清理源数据（`delete_after_verify`）
- 关联表与主表保持相同的时间分片与命名规则（基于主表的目标表规则）

## 环境要求
- PHP `^8.0`
- Laravel 组件：`illuminate/support`、`illuminate/database`（兼容 8/9/10）

## 安装
```bash
composer require yc/laravel-data-migrator
```

## 发布配置
将包内默认配置发布到应用：
```bash
php artisan vendor:publish --provider="YC\DataMigrator\DataMigratorServiceProvider" --tag=config
# 或者（若你的环境支持 tag ）
php artisan vendor:publish --tag=config
```
会生成 `config/data_migration.php`。

## 配置说明（`config/data_migration.php`）
示例：
```php
return [
    'migrations' => [
        [
            'name' => 'Example Migration',

            // 主表与关联关系
            'tables' => [
                [
                    'table' => 'users',
                    'date_column' => 'created_at',
                    'conditions' => [
                        ['status', '=', 'active'],
                        ['created_at', '>', '2020-01-01']
                    ],
                    'local_key' => 'id',
                    'relationships' => [
                        [
                            'table' => 'posts',
                            'local_key' => 'id',
                            'foreign_key' => 'user_id',
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
            'mode' => 1, // 1 = yearly（按年）

            // 连接配置
            'source_connection' => 'mysql',
            'target_connection' => 'target',

            // 分批大小
            'chunk' => 500,

            // 校验后是否删除源数据
            'delete_after_verify' => false,

            // 保留最近 N 年/月的数据（只迁移更早数据）
            'retain' => 1,

            // 步进：按年/月的分组步长（如 2 = 两年或两个月一组）
            'step' => 1,

            // 目标表命名规则（支持占位符）
            // 可用占位符: {%table}, {%year}, {%start_year}, {%end_year}, {%month}, {%start_month}, {%end_month}
            'table_suffix' => '{%table}_{%year}',
        ]
    ],

    'modes' => [
        1 => 'yearly', // 当前启用：按年迁移
    ],
];
```

### 命名规则与步进（`step`）
- 当 `mode=1`（按年）且 `step=1`：目标表示例 `users_2021`, `users_2022`
- 当 `mode=1` 且 `step=2`：目标表示例 `users_2021_2022`, `users_2023_2024`
- 表名由 `table_suffix` 控制（如 `'{%table}_{%start_year}_{%end_year}'`）

### 关联关系命名与分片原则
- 关联表与主表复用相同的时间分片与命名规则（例如主表 `users_2021_2022`，关联表 `posts_2021_2022`、`comments_2021_2022`）
- 禁止为关联表单独生成分片（不重复查询 MIN/MAX），严格以主表分片为准

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
5. 可选清理源数据（`delete_after_verify`）

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
