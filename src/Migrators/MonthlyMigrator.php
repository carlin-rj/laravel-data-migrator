<?php

namespace Carlin\DataMigrator\Migrators;

use Illuminate\Database\Connection;
use Carbon\Carbon;

class MonthlyMigrator extends BaseMigrator
{
    /**
     * 生成时间段（按月份）
     */
    protected function generatePeriods(string $minDate, int $retain): array
    {
        $periods = [];
        $start = Carbon::parse($minDate)->startOfMonth();
        $retainFromDate = Carbon::now()->subMonths($retain)->startOfMonth();
        $step = $this->config['step'] ?? 1; // 步进，默认为1个月

        $current = clone $start;
        while ($current < $retainFromDate) {
            // 计算结束月份
            $endMonth = $current->copy()->addMonths($step - 1)->endOfMonth();

            // 确保不超过保留范围
            if ($endMonth >= $retainFromDate) {
                $endMonth = $retainFromDate->copy()->subMonth()->endOfMonth();
            }

			// 多月模式
			$periods[] = [
				'start_year' => $current->year,
				'start_month' => $current->format('m'),
				'end_year' => $endMonth->year,
				'end_month' => $endMonth->format('m'),
				'start' => $current->copy()->startOfMonth()->format('Y-m-d H:i:s'),
				'end' => $endMonth->format('Y-m-d H:i:s'),
			];

            $current->addMonths($step);
        }

        return $periods;
    }

    /**
     * 生成目标表名（按月份）
     */
    protected function generateTargetTableName(string $originalTable, array $period): string
    {
		$suffix = $this->config['table_suffix'] ?? '';

		if ($suffix === '') {
			return $originalTable;
		}

		$startYear = $period['start_year'] ?? '';
		$startMonth = $period['start_month'] ?? '';
		$endYear = $period['end_year'] ?? '';
		$endMonth = $period['end_month'] ?? '';

		// 支持自定义格式
		return str_replace(
			['{%table}', '{%year}', '{%month}', '{%start_year}', '{%start_month}', '{%end_year}', '{%end_month}'],
			[$originalTable, $startYear, $startMonth, $startYear, $startMonth, $endYear, $endMonth],
			$suffix
		);

    }
}
