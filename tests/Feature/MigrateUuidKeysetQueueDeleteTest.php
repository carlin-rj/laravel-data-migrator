<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Carlin\DataMigrator\Migrators\YearlyMigrator;
use Carlin\DataMigrator\Jobs\DeleteSourceBatchJob;

class MigrateUuidKeysetQueueDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();  // 需要预创建目标表，因为直接调用 migrateData()
    }

    public function test_yearly_migration_with_uuid_key_dispatches_delete_job_and_inserts_target_rows()
    {
        // 准备源数据（UUID 主键，落在 2021 年）
        DB::connection('source')->table('users')->insert([
            ['id' => 'b3b8a2e4-1111-4444-8888-000000000001', 'name' => 'Alice', 'created_at' => '2021-01-10 00:00:00'],
            ['id' => 'c4c9b3f5-2222-5555-9999-000000000002', 'name' => 'Bob',   'created_at' => '2021-02-05 00:00:00'],
        ]);

        // 配置（按年、步进=1；禁用自动建表克隆，使用我们在 TestCase 中预建的目标表）
        $config = [
            'tables' => [[
                'table' => 'users',
                'date_column' => 'created_at',
                'local_key' => 'id',
                // 关联表留空，避免 chunkById 非数值键在关系流程中产生约束问题
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
            'delete' => true,  // 启用删除队列调度
        ];

        // 断言删除队列任务将被调度
        Bus::fake();

        // 实例化 YearlyMigrator 并执行
        $migrator = new YearlyMigrator($config, DB::connection('source'), DB::connection('target'));
        $migrator->preparePeriods();
        // 不调用 createSchema（使用已创建的目标表）
        $migrator->migrateData();

        // 验证目标表写入
        $this->assertEquals(2, DB::connection('target')->table('users_2021')->count());

        // 验证队列任务被调度（删除源数据）
        Bus::assertDispatched(DeleteSourceBatchJob::class, function ($job) {
            // 基本参数校验
            $ref = new \ReflectionClass($job);
            $sourceConn = $ref->getProperty('sourceConnectionName');
            $sourceConn->setAccessible(true);

            $table = $ref->getProperty('table');
            $table->setAccessible(true);

            $ids = $ref->getProperty('ids');
            $ids->setAccessible(true);

            return $sourceConn->getValue($job) === 'source'
                && $table->getValue($job) === 'users'
                && count($ids->getValue($job)) === 2;
        });
    }
}
