<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Carlin\DataMigrator\Migrators\YearlyMigrator;
use Carlin\DataMigrator\Jobs\DeleteSourceBatchJob;

class MigrateBothKeyTypesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();  // 需要预创建目标表，因为直接调用 migrateData()
    }

    public function test_migration_handles_both_uuid_and_int_keys_correctly()
    {
        // 准备 UUID 主键表数据
        DB::connection('source')->table('users')->insert([
            ['id' => 'uuid-user-001', 'name' => 'Alice UUID', 'created_at' => '2021-03-10 00:00:00'],
            ['id' => 'uuid-user-002', 'name' => 'Bob UUID',   'created_at' => '2021-07-20 00:00:00'],
        ]);

        // 准备 INT 主键表数据
        DB::connection('source')->table('orders')->insert([
            ['user_id' => 1, 'amount' => 150.00, 'created_at' => '2021-03-15 10:00:00'],
            ['user_id' => 2, 'amount' => 250.00, 'created_at' => '2021-07-25 12:30:00'],
        ]);

        $config = [
            'tables' => [
                [
                    'table' => 'users',
                    'date_column' => 'created_at',
                    'local_key' => 'id',  // UUID 主键
                    'relationships' => [],
                ],
                [
                    'table' => 'orders',
                    'date_column' => 'created_at',
                    'local_key' => 'id',  // INT 主键（自增）
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
            'auto_migrate_schema' => true,
            'delete' => true,
        ];

        Bus::fake();

        $migrator = new YearlyMigrator($config, DB::connection('source'), DB::connection('target'));
        $migrator->preparePeriods();
        $migrator->migrateData();

        // 验证 UUID 主键表（使用复合键 Keyset 分页）
        $this->assertEquals(2, DB::connection('target')->table('users_2021')->count());
        $alice = DB::connection('target')->table('users_2021')->where('id', 'uuid-user-001')->first();
        $this->assertEquals('Alice UUID', $alice->name);

        // 验证 INT 主键表（使用 chunkById 分页）
        $this->assertEquals(2, DB::connection('target')->table('orders_2021')->count());
        $order = DB::connection('target')->table('orders_2021')->where('user_id', 1)->first();
        $this->assertEquals(150.00, $order->amount);

        // 验证两种主键类型都调度了删除任务（各自一个批次）
        Bus::assertDispatched(DeleteSourceBatchJob::class, 2);
    }
}
