<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Carlin\DataMigrator\Migrators\YearlyMigrator;
use Carlin\DataMigrator\Jobs\DeleteSourceBatchJob;

class MigrateNumericKeyChunkByIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();  // 需要预创建目标表，因为直接调用 migrateData()
    }

    public function test_yearly_migration_with_numeric_key_uses_chunk_by_id_and_dispatches_delete_job()
    {
        // 准备源数据（数值主键，落在 2021 年）
        DB::connection('source')->table('orders')->insert([
            ['user_id' => 'user-001', 'amount' => 100.50, 'created_at' => '2021-03-15 10:00:00'],
            ['user_id' => 'user-002', 'amount' => 200.00, 'created_at' => '2021-05-20 12:30:00'],
            ['user_id' => 'user-003', 'amount' => 50.75,  'created_at' => '2021-08-10 08:15:00'],
        ]);

        $config = [
            'tables' => [[
                'table' => 'orders',
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
            'auto_migrate_schema' => false,
            'delete' => true,
        ];

        Bus::fake();

        $migrator = new YearlyMigrator($config, DB::connection('source'), DB::connection('target'));
        $migrator->preparePeriods();
        $migrator->migrateData();

        // 验证目标表写入（数值主键走 chunkById 分支）
        $this->assertEquals(3, DB::connection('target')->table('orders_2021')->count());

        // 验证金额数据正确
        $firstOrder = DB::connection('target')->table('orders_2021')->where('user_id', 'user-001')->first();
        $this->assertEquals(100.50, $firstOrder->amount);

        // 验证队列任务被调度
        Bus::assertDispatched(DeleteSourceBatchJob::class);
    }
}
