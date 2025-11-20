<?php

namespace Carlin\DataMigrator;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Carlin\DataMigrator\Migrators\YearlyMigrator;
use Carlin\DataMigrator\Migrators\MonthlyMigrator;
use Carlin\DataMigrator\Migrators\MigratorInterface;

class DataMigrator
{
    protected array $config;
    protected Connection $targetConnection;
    protected Connection $sourceConnection;
    protected MigratorInterface $migrator;

	public function setConfig(array $config): static
	{
        $this->config = $config;

        // 设置配置后立即初始化连接
        $this->setupConnections();

		return $this;
	}

    /**
     * 设置源数据库和目标数据库连接
     */
    protected function setupConnections(): void
    {
        // 获取源数据库连接（默认连接或配置的连接）
        $sourceConnectionName = $this->config['source_connection'] ?? 'mysql';
        $this->sourceConnection = DB::connection($sourceConnectionName);

        // 配置目标数据库连接
        $targetConnectionName = $this->config['target_connection'] ?? 'target';
        $this->targetConnection = DB::connection($targetConnectionName);
    }

    /**
     * 创建迁移器实例
     */
    protected function createMigrator(): void
    {
		if (! isset($this->config)) {
			throw new \InvalidArgumentException('请先设置迁移配置');
		}

        $mode = $this->config['mode'] ?? 1;

        match ($mode) {
            1 => $this->migrator = new YearlyMigrator($this->config, $this->sourceConnection, $this->targetConnection),
            2 => $this->migrator = new MonthlyMigrator($this->config, $this->sourceConnection, $this->targetConnection),
            default => throw new \InvalidArgumentException("不支持的迁移模式: {$mode}")
        };
    }

    /**
     * 执行数据迁移
     */
    public function migrate(): void
    {
        // 0. 预处理：生成所有表的时间段信息（只查询一次）
		$this->createMigrator();


		$this->migrator->preparePeriods();

        // 1. 自动创建表结构
        $this->migrator->createSchema();

        // 2. 迁移数据
        $this->migrator->migrateData();
    }
}
