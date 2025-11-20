<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Carlin\DataMigrator\Migrators\YearlyMigrator;

class MigrateWithStepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();  // 需要预创建目标表，因为直接调用 migrateData()
    }

    public function test_yearly_migration_with_step_2_groups_two_years_into_one_table()
    {
        // 准备源数据（UUID 主键，跨 2020 和 2021 两年）
        DB::connection('source')->table('users')->insert([
            ['id' => 'uuid-2020-01', 'name' => 'User 2020-1', 'created_at' => '2020-03-10 00:00:00'],
            ['id' => 'uuid-2020-02', 'name' => 'User 2020-2', 'created_at' => '2020-09-15 00:00:00'],
            ['id' => 'uuid-2021-01', 'name' => 'User 2021-1', 'created_at' => '2021-02-20 00:00:00'],
            ['id' => 'uuid-2021-02', 'name' => 'User 2021-2', 'created_at' => '2021-11-30 00:00:00'],
        ]);

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
            'retain' => 2,  // 保留最近 2 年（2023、2024），迁移 2020-2021
            'step' => 2,    // 每 2 年一个表
            'table_suffix' => '{%table}_{%start_year}_{%end_year}',
            'auto_migrate_schema' => false,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = new YearlyMigrator($config, DB::connection('source'), DB::connection('target'));
        $migrator->preparePeriods();
        $migrator->migrateData();

        // 验证 UUID 主键：2020-2021 两年数据迁移到 users_2020_2021 一张表
        $this->assertEquals(4, DB::connection('target')->table('users_2020_2021')->count());

        // 验证 UUID 主键：不同年份的数据都在同一表中
        $user2020 = DB::connection('target')->table('users_2020_2021')
            ->where('id', 'uuid-2020-01')
            ->first();
        $this->assertEquals('User 2020-1', $user2020->name);

        $user2021 = DB::connection('target')->table('users_2020_2021')
            ->where('id', 'uuid-2021-02')
            ->first();
        $this->assertEquals('User 2021-2', $user2021->name);
    }
}
