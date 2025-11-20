<?php

namespace Carlin\DataMigrator\Migrators;

use Illuminate\Database\Connection;
use Carlin\DataMigrator\Jobs\DeleteSourceBatchJob;
use Illuminate\Support\Collection;

abstract class BaseMigrator implements MigratorInterface
{
	protected array      $config;
	protected Connection $targetConnection;
	protected Connection $sourceConnection;
	protected array      $tablePeriods = [];

	public function __construct(array $config, Connection $sourceConnection, Connection $targetConnection)
	{
		$this->config           = $config;
		$this->sourceConnection = $sourceConnection;
		$this->targetConnection = $targetConnection;
	}

	/**
	 * 预处理：为所有表生成时间段信息
	 */
	public function preparePeriods(): void
	{
		foreach ($this->config['tables'] ?? [] as $tableConfig) {
			$this->prepareTablePeriods($tableConfig);
		}
	}

	/**
	 * 为单个表及其关联表准备时间段
	 */
	protected function prepareTablePeriods(array $tableConfig): void
	{
		$table = $tableConfig['table'] ?? null;
		if (!$table || isset($this->tablePeriods[$table])) {
			return;
		}

		// 获取日期字段
		$dateField = $tableConfig['date_column'] ?? 'created_at';

		// 获取日期范围（只查询一次）
		$minDate = $this->sourceConnection->table($table)->min($dateField);

		if (!$minDate) {
			$this->tablePeriods[$table] = [];
			return;
		}

		// 保留最近N的数据
		$retain = $this->config['retain'] ?? 1;

		// 生成时间段并缓存
		$this->tablePeriods[$table] = $this->generatePeriods($minDate, $retain);
	}

	/**
	 * 创建表结构
	 */
	public function createSchema(): void
	{
		if (!($this->config['auto_migrate_schema'] ?? true)) {
			return;
		}

		foreach ($this->config['tables'] ?? [] as $tableConfig) {
			$this->createTableSchema($tableConfig);
		}
	}

	/**
	 * 为单个表创建结构
	 */
	protected function createTableSchema(array $tableConfig): void
	{
		$table = $tableConfig['table'] ?? null;
		if (!$table) {
			return;
		}

		// 使用缓存的时间段
		$periods = $this->tablePeriods[$table] ?? [];

		// 为每个时间段创建表结构
		foreach ($periods as $period) {
			$targetTable = $this->generateTargetTableName($table, $period);
			$this->ensureTableExists($table, $targetTable);

			// 为关联表创建结构（使用主表相同的时间段）
			if (!empty($tableConfig['relationships'])) {
				$this->createRelationshipTablesSchema($tableConfig['relationships'], $period);
			}
		}
	}

	/**
	 * 为关联表创建结构（使用主表的时间段）
	 */
	protected function createRelationshipTablesSchema(array $relationships, array $period): void
	{
		foreach ($relationships as $relationship) {
			$relTable = $relationship['table'] ?? null;
			if (!$relTable) {
				continue;
			}

			// 关联表使用相同的时间段规则
			$targetTable = $this->generateTargetTableName($relTable, $period);
			$this->ensureTableExists($relTable, $targetTable);

			// 递归处理更深层的关联
			if (!empty($relationship['relationships'])) {
				$this->createRelationshipTablesSchema($relationship['relationships'], $period);
			}
		}
	}


	/**
	 * 确保目标表存在
	 */
	protected function ensureTableExists(string $sourceTableName, string $targetTableName): void
	{
		if (!$this->targetConnection->getSchemaBuilder()->hasTable($targetTableName)) {
			$this->cloneTableStructure($sourceTableName, $targetTableName);
		}
	}

	/**
	 * 克隆表结构
	 */
	protected function cloneTableStructure(string $sourceTableName, string $targetTableName): void
	{
		// 获取源表的列信息
		$row = $this->sourceConnection->selectOne("SHOW CREATE TABLE `{$sourceTableName}`");

		$createSql = $row->{'Create Table'};

		//替换表名
		$createSql = str_replace($sourceTableName, $targetTableName, $createSql);

		//创建目标表
		$this->targetConnection->statement($createSql);
	}


	/**
	 * 迁移单个表的数据
	 */
	protected function migrateTableData(array $tableConfig): void
	{
		$table = $tableConfig['table'] ?? null;
		if (!$table) {
			return;
		}

		$dateField = $tableConfig['date_column'] ?? 'created_at';
		$chunk     = $this->config['chunk'] ?? 500;

		// 使用缓存的时间段
		$periods = $this->tablePeriods[$table] ?? [];

		// 遍历每个时间段
		foreach ($periods as $period) {
			$targetTable = $this->generateTargetTableName($table, $period);

			// 构建查询
			$query = $this->sourceConnection->table($table)->whereBetween($dateField, [
					$period['start'],
					$period['end']
				]);

			// 应用条件
			if (!empty($tableConfig['conditions'])) {
				if (is_array($tableConfig['conditions'])) {
					foreach ($tableConfig['conditions'] as $condition) {
						$query->where(...$condition);
					}
				}

				if (is_callable($tableConfig['conditions'])) {
					$tableConfig['conditions']($query);
				}
			}

			// 分批迁移数据
			$localKey = $tableConfig['local_key'] ?? 'id';

			// 自动检测主键是否为数值型；非数值（如 UUID）改用复合键 Keyset 分页
			$firstKeyVal  = $this->sourceConnection->table($table)->min($localKey);
			$isNumericKey = is_numeric($firstKeyVal);

			if ($isNumericKey) {
				$query->chunkById($chunk, function ($rows) use ($table, $localKey, $targetTable, $tableConfig, $period) {
					if ($rows->isEmpty()) {
						return;
					}
					//开启事务
					$this->processData($table, $rows, $localKey, $targetTable, $tableConfig, $period);
				}, $localKey);
			} else {
				$lastDate = null;
				$lastKey  = null;

				while (true) {
					$keysetQuery = clone $query;

					if ($lastDate !== null) {
						$keysetQuery->where(function ($q) use ($dateField, $localKey, $lastDate, $lastKey) {
							$q->where($dateField, '>', $lastDate)->orWhere(function ($q2) use ($dateField, $localKey, $lastDate, $lastKey) {
									$q2->where($dateField, '=', $lastDate)->where($localKey, '>', $lastKey);
								});
						});
					}

					$rows = $keysetQuery->orderBy($dateField)->orderBy($localKey)->limit($chunk)->get();

					if ($rows->isEmpty()) {
						break;
					}

					$this->processData($table, $rows, $localKey, $targetTable, $tableConfig, $period);

					$last     = $rows->last();
					$lastDate = $last->{$dateField};
					$lastKey  = $last->{$localKey};
				}
			}
		}
	}

	// 处理数据
	protected function processData(string $table, Collection $rows, string $localKey, string $targetTable, array $tableConfig, array $period): void
	{
		$this->targetConnection->transaction(function () use ($table, $rows, $localKey, $targetTable, $tableConfig, $period) {
			$data = $rows->map(fn($r) => (array) $r)->all();

			$this->targetConnection->table($targetTable)->insertOrIgnore($data);

			$parentIds = array_column($data, $localKey);
			if (!empty($tableConfig['relationships'])) {
				$this->migrateRelationshipData($tableConfig['relationships'], $parentIds, $period);
			}

			// 队列删除源数据（事务提交后入队）
			if ($this->config['delete'] ?? false) {
				DeleteSourceBatchJob::dispatch($this->config['source_connection'], $table, $parentIds, $localKey, $this->config['chunk'] ?? 500)->afterCommit();
			}
		});
	}


	/**
	 * 迁移关联表数据
	 */
	protected function migrateRelationshipData(array $relationships, array $parentIds, array $period): void
	{
		if (empty($parentIds)) {
			return;
		}

		$chunk = $this->config['chunk'] ?? 500;
		foreach (array_chunk($parentIds, $chunk) as $parentIdList) {
			foreach ($relationships as $relationship) {
				$table = $relationship['table'] ?? null;
				if (!$table) {
					continue;
				}

				$targetTable = $this->generateTargetTableName($table, $period);
				$foreignKey  = $relationship['foreign_key'] ?? 'id';

				// 查询关联数据
				$query = $this->sourceConnection->table($table)->whereIn($foreignKey, $parentIdList);

				// 分批迁移关联数据
				$query->chunkById($chunk, function ($rows) use ($table, $targetTable, $relationship, $period) {
					if ($rows->isEmpty()) {
						return;
					}

					$data = $rows->map(fn($r) => (array) $r)->all();
					$this->targetConnection->table($targetTable)->insertOrIgnore($data);

					// 递归处理更深层的关联
					$localKey       = $relationship['local_key'] ?? 'id';
					$childParentIds = array_column($data, $localKey);
					if (!empty($relationship['relationships'])) {
						$this->migrateRelationshipData($relationship['relationships'], $childParentIds, $period);
					}

					// 队列删除源数据（事务提交后入队）
					if ($this->config['delete'] ?? false) {
						DeleteSourceBatchJob::dispatch($this->config['source_connection'], $table, $childParentIds, $localKey, $this->config['chunk'] ?? 500)->afterCommit();
					}
				}, $foreignKey);
			}
		}
	}

	/**
	 * 生成时间段
	 */
	abstract protected function generatePeriods(string $minDate, int $retain): array;

	/**
	 * 生成目标表名
	 */
	abstract protected function generateTargetTableName(string $originalTable, array $period): string;


	/**
	 * 迁移数据
	 */
	public function migrateData(): void
	{
		foreach ($this->config['tables'] ?? [] as $tableConfig) {
			$this->migrateTableData($tableConfig);
		}
	}
}
