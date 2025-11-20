<?php

namespace Carlin\DataMigrator\Migrators;

use Carbon\Carbon;

class YearlyMigrator extends BaseMigrator
{
    /**
     * 生成时间段
     */
    protected function generatePeriods(string $minDate, int $retain): array
    {
        $periods = [];
        $startYear = Carbon::parse($minDate)->year;
        $currentYear = Carbon::now()->year;
        $step = $this->config['step'] ?? 1; // 步进，默认为1年

        // 计算截止年份（保留最近N年）
        $retainFromYear = $currentYear - $retain;

        for ($year = $startYear; $year < $retainFromYear; $year += $step) {
            // 计算结束年份
            $endYear = min($year + $step - 1, $retainFromYear - 1);

			$periods[] = [
				'start_year' => $year,
				'end_year' => $endYear,
				'start' => "{$year}-01-01 00:00:00",
				'end' => "{$endYear}-12-31 23:59:59",
			];
        }

        return $periods;
    }

    /**
     * 生成目标表名（按年份）
     */
    protected function generateTargetTableName(string $originalTable, array $period): string
    {
        $suffix = $this->config['table_suffix'] ?? '';

		if ($suffix === '') {
			return $originalTable;
		}

		$startYear = $period['start_year'];
		$endYear = $period['end_year'];

		return str_replace(
			['{%table}', '{%year}', '{%start_year}', '{%end_year}'],
			[$originalTable, $startYear, $startYear, $endYear],
			$suffix
		);
    }

}
