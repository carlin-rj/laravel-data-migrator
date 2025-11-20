<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Carlin\DataMigrator\Migrators\YearlyMigrator;

class MigrateWithRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTargetTables();  // 需要预创建目标表，因为直接调用 migrateData()
    }

    public function test_yearly_migration_migrates_related_tables_with_same_period()
    {
        // 准备主表数据（users，UUID 主键）
        DB::connection('source')->table('users')->insert([
            ['id' => 'user-uuid-001', 'name' => 'Alice', 'created_at' => '2021-03-10 00:00:00'],
            ['id' => 'user-uuid-002', 'name' => 'Bob',   'created_at' => '2021-07-20 00:00:00'],
        ]);

        // 准备关联表数据（posts，数值主键，通过 user_id 外键关联）
        DB::connection('source')->table('posts')->insert([
            ['user_id' => 'user-uuid-001', 'title' => 'Alice Post 1', 'created_at' => '2021-03-11 00:00:00'],
            ['user_id' => 'user-uuid-001', 'title' => 'Alice Post 2', 'created_at' => '2021-03-15 00:00:00'],
            ['user_id' => 'user-uuid-002', 'title' => 'Bob Post 1',   'created_at' => '2021-07-21 00:00:00'],
        ]);

        $config = [
            'tables' => [[
                'table' => 'users',
                'date_column' => 'created_at',
                'local_key' => 'id',
                'relationships' => [
                    [
                        'table' => 'posts',
                        'local_key' => 'id',
                        'foreign_key' => 'user_id',
                    ]
                ],
            ]],
            'mode' => 1,
            'source_connection' => 'source',
            'target_connection' => 'target',
            'chunk' => 50,
            'retain' => 1,
            'step' => 1,
            'table_suffix' => '{%table}_{%year}',
            'auto_migrate_schema' => false,
            'delete' => false,
        ];

        Bus::fake();

        $migrator = new YearlyMigrator($config, DB::connection('source'), DB::connection('target'));
        $migrator->preparePeriods();
        $migrator->migrateData();

        // 验证主表迁移
        $this->assertEquals(2, DB::connection('target')->table('users_2021')->count());

        // 验证关联表也迁移到相同的 period（posts_2021，复用主表时间段规则）
        $this->assertEquals(3, DB::connection('target')->table('posts_2021')->count());

        // 验证关联数据正确性
        $alicePosts = DB::connection('target')->table('posts_2021')
            ->where('user_id', 'user-uuid-001')
            ->count();
        $this->assertEquals(2, $alicePosts);

        $bobPosts = DB::connection('target')->table('posts_2021')
            ->where('user_id', 'user-uuid-002')
            ->count();
        $this->assertEquals(1, $bobPosts);
    }
}
