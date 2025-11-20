<?php

namespace Carlin\DataMigrator\Console\Commands;

use Illuminate\Console\Command;
use Carlin\DataMigrator\DataMigrator;

class MigrateDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:migrate {--name= : 指定迁移配置}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '迁移数据到目标数据库';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
		ini_set('memory_limit', '-1');
        $this->info('开始数据迁移...');
        try {
            // 获取配置
            $configName = $this->option('name') ?? '';
            $configs = config('data_migration.migrations', []);

			$config = collect($configs)->firstWhere('name', $configName);

            if (!$config) {
                $this->error("配置 {$configName} 不存在");
                return 1;
            }

            // 显示配置信息
            $this->info('迁移配置:');
            $this->line('  名称: ' . ($config['name'] ?? 'Unnamed'));
            $modes = config('data_migration.modes', []);
            $mode = $config['mode'] ?? 1;
            $this->line('  模式: ' . ($modes[$mode] ?? 'unknown') . " (mode={$mode})");
            $this->line('  源数据库: ' . ($config['source_connection'] ?? 'mysql'));
            $this->line('  目标数据库: ' . ($config['target_connection'] ?? 'target'));
            $this->line('  批量大小: ' . ($config['chunk'] ?? 500));
            $this->line('  保留期限: ' . ($config['retain'] ?? 1));
            $this->line('  迁移后删除: ' . ($config['delete_after_verify'] ? '是' : '否'));
            $this->newLine();

            // 创建迁移器实例
            $migrator = new DataMigrator();
			$migrator->setConfig($config);

            // 执行迁移
			$startTime = microtime(true);

			$migrator->migrate();

			$duration = round(microtime(true) - $startTime, 2);
			$this->newLine();
			$this->info("数据迁移完成! 耗时: {$duration} 秒");


            return 0;
        } catch (\Exception $e) {
            $this->error('数据迁移失败: ' . $e->getMessage());
            $this->error('堆栈跟踪: ' . $e->getTraceAsString());
            return 1;
        }
    }
}
