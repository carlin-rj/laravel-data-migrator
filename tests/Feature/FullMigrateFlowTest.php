<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Carlin\DataMigrator\DataMigrator;
use Carlin\DataMigrator\Jobs\DeleteSourceBatchJob;

class FullMigrateFlowTest extends TestCase
{
    public function test_full_migrate_flow_with_auto_create_schema_and_queue_delete()
    {
        // 准备 UUID 主键表数据
        DB::connection('source')->table('users')->insert([
            ['id' => 'flow-uuid-001', 'name' => 'Alice Flow', 'created_at' => '2021-03-10 00:00:00'],
            ['id' => 'flow-uuid-002', 'name' => 'Bob Flow',   'created_at' => '2021-07-20 00:00:00'],
        ]);

        // 准备 INT 主键表数据
        DB::connection('source')->table('orders')->insert([
            ['user_id' => 'flow-uuid-001', 'amount' => 300.00, 'created_at' => '2021-04-15 10:00:00'],
            ['user_id' => 'flow-uuid-002', 'amount' => 400.00, 'created_at' => '2021-08-25 12:30:00'],
        ]);

        // 删除目标表，测试 auto_migrate_schema 自动建表功能
        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_2021');
        $targetSchema->dropIfExists('orders_2021');

        $config = [
            'tables' => [
                [
                    'table' => 'users',
                    'date_column' => 'created_at',
                    'local_key' => 'id',
                    'relationships' => [],
                ],
                [
                    'table' => 'orders',
                    'date_column' => 'created_at',
                    'local_key' => 'id',
                    'relationships' => [],
                ],
            ],
            'mode' => 1,
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 1,
            'table_suffix' => '{%table}_{%year}',
            'auto_migrate_schema' => true,  // 测试自动建表
            'delete' => true,               // 测试队列删除
        ];

        Bus::fake();

        // 使用完整流程
        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证目标表已自动创建
        $this->assertTrue($targetSchema->hasTable('users_2021'));
        $this->assertTrue($targetSchema->hasTable('orders_2021'));

        // 验证 UUID 主键表数据迁移
        $this->assertEquals(2, DB::connection('target')->table('users_2021')->count());
        $alice = DB::connection('target')->table('users_2021')->where('id', 'flow-uuid-001')->first();
        $this->assertEquals('Alice Flow', $alice->name);

        // 验证 INT 主键表数据迁移
        $this->assertEquals(2, DB::connection('target')->table('orders_2021')->count());
        $order = DB::connection('target')->table('orders_2021')->where('user_id', 'flow-uuid-001')->first();
        $this->assertEquals(300.00, $order->amount);

        // 验证删除任务被调度（2 个表各 1 次）
        Bus::assertDispatched(DeleteSourceBatchJob::class, 2);
    }

    public function test_full_migrate_flow_without_auto_create_schema()
    {
        // 准备源数据
        DB::connection('source')->table('users')->insert([
            ['id' => 'no-auto-001', 'name' => 'Carol', 'created_at' => '2021-05-10 00:00:00'],
        ]);

        // 手动预建目标表
        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_2021');
        $targetSchema->create('users_2021', function ($table) {
            $table->string('id');
            $table->string('name')->nullable();
            $table->timestamp('created_at');
        });

        $config = [
            'tables' => [[
                'table' => 'users',
                'date_column' => 'created_at',
                'local_key' => 'id',
                'relationships' => [],
            ]],
            'mode' => 1,
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 1,
            'table_suffix' => '{%table}_{%year}',
            'auto_migrate_schema' => false,  // 禁用自动建表
            'delete' => false,
        ];

        Bus::fake();

        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证数据迁移成功
        $this->assertEquals(1, DB::connection('target')->table('users_2021')->count());
        $carol = DB::connection('target')->table('users_2021')->where('id', 'no-auto-001')->first();
        $this->assertEquals('Carol', $carol->name);
    }

    public function test_full_migrate_with_step_parameter()
    {
        // 准备跨多年的数据（2020-2022）
        DB::connection('source')->table('users')->insert([
            ['id' => 'step-001', 'name' => 'User 2020', 'created_at' => '2020-06-15 00:00:00'],
            ['id' => 'step-002', 'name' => 'User 2021', 'created_at' => '2021-06-15 00:00:00'],
            ['id' => 'step-003', 'name' => 'User 2022', 'created_at' => '2022-06-15 00:00:00'],
        ]);

        DB::connection('source')->table('orders')->insert([
            ['user_id' => 'step-001', 'amount' => 100.00, 'created_at' => '2020-07-01 00:00:00'],
            ['user_id' => 'step-002', 'amount' => 200.00, 'created_at' => '2021-07-01 00:00:00'],
            ['user_id' => 'step-003', 'amount' => 300.00, 'created_at' => '2022-07-01 00:00:00'],
        ]);

        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_2020_2021');
        $targetSchema->dropIfExists('users_2022_2023');
        $targetSchema->dropIfExists('orders_2020_2021');
        $targetSchema->dropIfExists('orders_2022_2023');

        $config = [
            'tables' => [
                ['table' => 'users', 'date_column' => 'created_at', 'local_key' => 'id', 'relationships' => []],
                ['table' => 'orders', 'date_column' => 'created_at', 'local_key' => 'id', 'relationships' => []],
            ],
            'mode' => 1,
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 2,  // 两年合并成一个表
            'table_suffix' => '{%table}_{%start_year}_{%end_year}',
            'auto_migrate_schema' => true,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证表已自动创建（step=2，2020+2021 合并）
        $this->assertTrue($targetSchema->hasTable('users_2020_2021'));
        $this->assertTrue($targetSchema->hasTable('orders_2020_2021'));

        // 验证 2020-2021 数据合并到一个表
        $this->assertEquals(2, DB::connection('target')->table('users_2020_2021')->count());
        $this->assertEquals(2, DB::connection('target')->table('orders_2020_2021')->count());

        // 验证 2022 数据（step=2，2022+2023 合并，但 2023 无数据）
        $this->assertEquals(1, DB::connection('target')->table('users_2022_2023')->count());
        $this->assertEquals(1, DB::connection('target')->table('orders_2022_2023')->count());
    }

    public function test_full_migrate_with_relationships()
    {
        // 准备主表数据（UUID）
        DB::connection('source')->table('users')->insert([
            ['id' => 'rel-user-001', 'name' => 'Parent User', 'created_at' => '2021-05-10 00:00:00'],
        ]);

        // 准备关联表数据（INT）
        DB::connection('source')->table('orders')->insert([
            ['user_id' => 'rel-user-001', 'amount' => 500.00, 'created_at' => '2021-05-15 10:00:00'],
            ['user_id' => 'rel-user-001', 'amount' => 600.00, 'created_at' => '2021-05-20 12:00:00'],
        ]);

        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_2021');
        $targetSchema->dropIfExists('orders_2021');

        $config = [
            'tables' => [
                [
                    'table' => 'users',
                    'date_column' => 'created_at',
                    'local_key' => 'id',
                    'relationships' => [
                        [
                            'table' => 'orders',
                            'foreign_key' => 'user_id',
                            'local_key' => 'id',
                        ],
                    ],
                ],
            ],
            'mode' => 1,
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 1,
            'table_suffix' => '{%table}_{%year}',
            'auto_migrate_schema' => true,
            'delete' => true,
        ];

        Bus::fake();

        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证主表迁移
        $this->assertEquals(1, DB::connection('target')->table('users_2021')->count());

        // 验证关联表迁移（使用主表的时间段规则）
        $this->assertEquals(2, DB::connection('target')->table('orders_2021')->count());

        // 验证关联表数据正确性
        $orders = DB::connection('target')->table('orders_2021')->where('user_id', 'rel-user-001')->get();
        $this->assertCount(2, $orders);
        $this->assertEquals(500.00, $orders[0]->amount);
        $this->assertEquals(600.00, $orders[1]->amount);

        // 验证删除任务（主表1次 + 关联表批次）
        Bus::assertDispatched(DeleteSourceBatchJob::class);
    }

    public function test_full_migrate_multiple_periods()
    {
        // 准备多个年份的数据（2020-2022）
        DB::connection('source')->table('users')->insert([
            ['id' => 'period-001', 'name' => 'User 2020', 'created_at' => '2020-03-10 00:00:00'],
            ['id' => 'period-002', 'name' => 'User 2021', 'created_at' => '2021-03-10 00:00:00'],
            ['id' => 'period-003', 'name' => 'User 2022', 'created_at' => '2022-03-10 00:00:00'],
        ]);

        DB::connection('source')->table('orders')->insert([
            ['user_id' => 'period-001', 'amount' => 111.00, 'created_at' => '2020-04-01 00:00:00'],
            ['user_id' => 'period-002', 'amount' => 222.00, 'created_at' => '2021-04-01 00:00:00'],
            ['user_id' => 'period-003', 'amount' => 333.00, 'created_at' => '2022-04-01 00:00:00'],
        ]);

        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_2020');
        $targetSchema->dropIfExists('users_2021');
        $targetSchema->dropIfExists('users_2022');
        $targetSchema->dropIfExists('orders_2020');
        $targetSchema->dropIfExists('orders_2021');
        $targetSchema->dropIfExists('orders_2022');

        $config = [
            'tables' => [
                ['table' => 'users', 'date_column' => 'created_at', 'local_key' => 'id', 'relationships' => []],
                ['table' => 'orders', 'date_column' => 'created_at', 'local_key' => 'id', 'relationships' => []],
            ],
            'mode' => 1,
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 1,
            'table_suffix' => '{%table}_{%year}',
            'auto_migrate_schema' => true,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证每个年份的表都被创建
        $this->assertTrue($targetSchema->hasTable('users_2020'));
        $this->assertTrue($targetSchema->hasTable('users_2021'));
        $this->assertTrue($targetSchema->hasTable('users_2022'));
        $this->assertTrue($targetSchema->hasTable('orders_2020'));
        $this->assertTrue($targetSchema->hasTable('orders_2021'));
        $this->assertTrue($targetSchema->hasTable('orders_2022'));

        // 验证每个表的数据
        $this->assertEquals(1, DB::connection('target')->table('users_2020')->count());
        $this->assertEquals(1, DB::connection('target')->table('users_2021')->count());
        $this->assertEquals(1, DB::connection('target')->table('users_2022')->count());
        $this->assertEquals(1, DB::connection('target')->table('orders_2020')->count());
        $this->assertEquals(1, DB::connection('target')->table('orders_2021')->count());
        $this->assertEquals(1, DB::connection('target')->table('orders_2022')->count());
    }

    public function test_full_migrate_monthly_mode()
    {
        // 准备跨月数据
        DB::connection('source')->table('users')->insert([
            ['id' => 'month-001', 'name' => 'Jan User', 'created_at' => '2021-01-15 00:00:00'],
            ['id' => 'month-002', 'name' => 'Feb User', 'created_at' => '2021-02-15 00:00:00'],
            ['id' => 'month-003', 'name' => 'Mar User', 'created_at' => '2021-03-15 00:00:00'],
        ]);

        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_202101');
        $targetSchema->dropIfExists('users_202102');
        $targetSchema->dropIfExists('users_202103');

        $config = [
            'tables' => [[
                'table' => 'users',
                'date_column' => 'created_at',
                'local_key' => 'id',
                'relationships' => [],
            ]],
            'mode' => 2,  // 按月迁移
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 1,
            'table_suffix' => '{%table}_{%year}{%month}',
            'auto_migrate_schema' => true,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证按月分表创建
        $this->assertTrue($targetSchema->hasTable('users_202101'));
        $this->assertTrue($targetSchema->hasTable('users_202102'));
        $this->assertTrue($targetSchema->hasTable('users_202103'));

        // 验证每月数据
        $this->assertEquals(1, DB::connection('target')->table('users_202101')->count());
        $this->assertEquals(1, DB::connection('target')->table('users_202102')->count());
        $this->assertEquals(1, DB::connection('target')->table('users_202103')->count());

        // 验证数据正确性
        $jan = DB::connection('target')->table('users_202101')->first();
        $this->assertEquals('Jan User', $jan->name);
    }

    public function test_full_migrate_monthly_mode_with_step()
    {
        // 准备跨月数据（2021-01 到 2021-04）
        DB::connection('source')->table('users')->insert([
            ['id' => 'mstep-001', 'name' => 'Jan', 'created_at' => '2021-01-10 00:00:00'],
            ['id' => 'mstep-002', 'name' => 'Feb', 'created_at' => '2021-02-10 00:00:00'],
            ['id' => 'mstep-003', 'name' => 'Mar', 'created_at' => '2021-03-10 00:00:00'],
            ['id' => 'mstep-004', 'name' => 'Apr', 'created_at' => '2021-04-10 00:00:00'],
        ]);

        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_202101_202102');
        $targetSchema->dropIfExists('users_202103_202104');

        $config = [
            'tables' => [[
                'table' => 'users',
                'date_column' => 'created_at',
                'local_key' => 'id',
                'relationships' => [],
            ]],
            'mode' => 2,  // 按月迁移
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 2,  // 两个月合并
            'table_suffix' => '{%table}_{%start_year}{%start_month}_{%end_year}{%end_month}',
            'auto_migrate_schema' => true,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证两个月合并成一个表
        $this->assertTrue($targetSchema->hasTable('users_202101_202102'));
        $this->assertTrue($targetSchema->hasTable('users_202103_202104'));

        // 验证数据合并正确
        $this->assertEquals(2, DB::connection('target')->table('users_202101_202102')->count());
        $this->assertEquals(2, DB::connection('target')->table('users_202103_202104')->count());
    }

    public function test_full_migrate_with_retain_parameter()
    {
        // 准备数据：2020-2024（5年）
        DB::connection('source')->table('users')->insert([
            ['id' => 'retain-2020', 'name' => 'Old 2020', 'created_at' => '2020-06-01 00:00:00'],
            ['id' => 'retain-2021', 'name' => 'Old 2021', 'created_at' => '2021-06-01 00:00:00'],
            ['id' => 'retain-2022', 'name' => 'Old 2022', 'created_at' => '2022-06-01 00:00:00'],
            ['id' => 'retain-2023', 'name' => 'Recent 2023', 'created_at' => '2023-06-01 00:00:00'],
            ['id' => 'retain-2024', 'name' => 'Current 2024', 'created_at' => '2024-06-01 00:00:00'],
        ]);

        $targetSchema = DB::connection('target')->getSchemaBuilder();
        $targetSchema->dropIfExists('users_2020');
        $targetSchema->dropIfExists('users_2021');
        $targetSchema->dropIfExists('users_2022');
        $targetSchema->dropIfExists('users_2023');  // 清理可能存在的表
        $targetSchema->dropIfExists('users_2024');  // 清理可能存在的表

        $config = [
            'tables' => [[
                'table' => 'users',
                'date_column' => 'created_at',
                'local_key' => 'id',
                'relationships' => [],
            ]],
            'mode' => 1,
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 2,  // 保留最近2年（2023-2024），迁移 2020-2022
            'step' => 1,
            'table_suffix' => '{%table}_{%year}',
            'auto_migrate_schema' => true,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = (new DataMigrator())->setConfig($config);
        $migrator->migrate();

        // 验证只迁移了 2020-2022（保留了 2023-2024）
        $this->assertTrue($targetSchema->hasTable('users_2020'));
        $this->assertTrue($targetSchema->hasTable('users_2021'));
        $this->assertTrue($targetSchema->hasTable('users_2022'));

        // 验证 2023-2024 没有被迁移（保留在源表）
        $this->assertFalse($targetSchema->hasTable('users_2023'));
        $this->assertFalse($targetSchema->hasTable('users_2024'));

        // 验证迁移的数据正确
        $this->assertEquals(1, DB::connection('target')->table('users_2020')->count());
        $this->assertEquals(1, DB::connection('target')->table('users_2021')->count());
        $this->assertEquals(1, DB::connection('target')->table('users_2022')->count());
    }
}
