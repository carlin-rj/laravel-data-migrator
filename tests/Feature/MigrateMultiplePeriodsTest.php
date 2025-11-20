<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Carlin\DataMigrator\Migrators\YearlyMigrator;

class MigrateMultiplePeriodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();  // 需要预创建目标表，因为直接调用 migrateData()
    }

    public function test_yearly_migration_with_multiple_years_creates_separate_tables()
    {
        // 准备源数据（UUID 主键，跨 2021 和 2022 两年）
        DB::connection('source')->table('users')->insert([
            ['id' => 'uuid-2021-01', 'name' => 'Alice 2021', 'created_at' => '2021-01-10 00:00:00'],
            ['id' => 'uuid-2021-02', 'name' => 'Bob 2021',   'created_at' => '2021-06-15 00:00:00'],
            ['id' => 'uuid-2022-01', 'name' => 'Carol 2022', 'created_at' => '2022-02-20 00:00:00'],
            ['id' => 'uuid-2022-02', 'name' => 'Dave 2022',  'created_at' => '2022-11-30 00:00:00'],
        ]);

        // 准备源数据（INT 主键，跨 2021 两年）
        DB::connection('source')->table('orders')->insert([
            ['user_id' => 'user-001', 'amount' => 100.00, 'created_at' => '2021-03-15 10:00:00'],
            ['user_id' => 'user-002', 'amount' => 200.00, 'created_at' => '2021-08-20 12:30:00'],
        ]);

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
            'retain' => 1,  // 保留最近 1 年（2024），迁移 2021、2022
            'step' => 1,
            'table_suffix' => '{%table}_{%year}',
            'auto_migrate_schema' => false,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = new YearlyMigrator($config, DB::connection('source'), DB::connection('target'));
        $migrator->preparePeriods();
        $migrator->migrateData();

        // 验证 UUID 主键：2021 年数据迁移到 users_2021
        $this->assertEquals(2, DB::connection('target')->table('users_2021')->count());
        $alice = DB::connection('target')->table('users_2021')->where('id', 'uuid-2021-01')->first();
        $this->assertEquals('Alice 2021', $alice->name);

        // 验证 UUID 主键：2022 年数据迁移到 users_2022
        $this->assertEquals(2, DB::connection('target')->table('users_2022')->count());
        $carol = DB::connection('target')->table('users_2022')->where('id', 'uuid-2022-01')->first();
        $this->assertEquals('Carol 2022', $carol->name);

        // 验证 INT 主键：2021 年数据迁移到 orders_2021
        $this->assertEquals(2, DB::connection('target')->table('orders_2021')->count());
        $order = DB::connection('target')->table('orders_2021')->where('user_id', 'user-001')->first();
        $this->assertEquals(100.00, $order->amount);
    }
}
