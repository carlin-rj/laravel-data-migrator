<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Illuminate\Support\Facades\DB;
use Carlin\DataMigrator\DataMigratorServiceProvider;

class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            DataMigratorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // 配置本地 MySQL 作为 source/target 两个连接（支持环境变量覆盖）
        $app['config']->set('database.default', 'source');
        $app['config']->set('database.connections.source', [
            'driver' => 'mysql',
            'host' => env('MYSQL_SOURCE_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('MYSQL_SOURCE_PORT', env('DB_PORT', '3306')),
            'database' => env('MYSQL_SOURCE_DATABASE', 'dm_source'),
            'username' => env('MYSQL_SOURCE_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('MYSQL_SOURCE_PASSWORD', env('DB_PASSWORD', '123456')),
            'unix_socket' => env('MYSQL_SOURCE_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => false,
            'engine'         => null,
            'options'        => [
				\PDO::ATTR_DEFAULT_FETCH_MODE,
				\PDO::FETCH_ASSOC
			],
        ]);
        $app['config']->set('database.connections.target', [
            'driver' => 'mysql',
            'host' => env('MYSQL_TARGET_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('MYSQL_TARGET_PORT', env('DB_PORT', '3306')),
            'database' => env('MYSQL_TARGET_DATABASE', 'dm_target'),
            'username' => env('MYSQL_TARGET_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('MYSQL_TARGET_PASSWORD', env('DB_PASSWORD', '123456')),
            'unix_socket' => env('MYSQL_TARGET_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => false,
            'engine'         => null,
            'options'        => [
				\PDO::ATTR_DEFAULT_FETCH_MODE,
				\PDO::FETCH_ASSOC
			],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 创建源库的基本表结构
        $this->createSourceTables();

        // 默认不预创建目标表，让各测试根据需要调用 createTargetTables()
    }

    /**
     * 创建源表结构
     */
    protected function createSourceTables(): void
    {
        $sourceSchema = DB::connection('source')->getSchemaBuilder();
        
        // UUID 主键表
        $sourceSchema->dropIfExists('users');
        $sourceSchema->create('users', function ($table) {
            $table->string('id');
            $table->string('name')->nullable();
            $table->timestamp('created_at');
        });

        // 数值主键表
        $sourceSchema->dropIfExists('orders');
        $sourceSchema->create('orders', function ($table) {
            $table->increments('id');
            $table->string('user_id')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('created_at');
        });

        // 关联表
        $sourceSchema->dropIfExists('posts');
        $sourceSchema->create('posts', function ($table) {
            $table->increments('id');
            $table->string('user_id');
            $table->string('title')->nullable();
            $table->timestamp('created_at');
        });
    }

    /**
     * 创建目标表结构（供不测试 auto_migrate_schema 的测试用例调用）
     */
    protected function createTargetTables(): void
    {
        $targetSchema = DB::connection('target')->getSchemaBuilder();
        
        $targetSchema->dropIfExists('users_2021');
        $targetSchema->create('users_2021', function ($table) {
            $table->string('id');
            $table->string('name')->nullable();
            $table->timestamp('created_at');
        });

        $targetSchema->dropIfExists('users_2022');
        $targetSchema->create('users_2022', function ($table) {
            $table->string('id');
            $table->string('name')->nullable();
            $table->timestamp('created_at');
        });

        $targetSchema->dropIfExists('orders_2021');
        $targetSchema->create('orders_2021', function ($table) {
            $table->increments('id');
            $table->string('user_id')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('created_at');
        });

        $targetSchema->dropIfExists('posts_2021');
        $targetSchema->create('posts_2021', function ($table) {
            $table->increments('id');
            $table->string('user_id');
            $table->string('title')->nullable();
            $table->timestamp('created_at');
        });

        // 支持步进测试：两年一表
        $targetSchema->dropIfExists('users_2020_2021');
        $targetSchema->create('users_2020_2021', function ($table) {
            $table->string('id');
            $table->string('name')->nullable();
            $table->timestamp('created_at');
        });
    }
}
