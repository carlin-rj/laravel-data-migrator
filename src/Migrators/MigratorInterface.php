<?php

namespace Carlin\DataMigrator\Migrators;

interface MigratorInterface
{
    /**
     * 预处理：生成时间段
     */
    public function preparePeriods(): void;

    /**
     * 创建表结构
     */
    public function createSchema(): void;

    /**
     * 迁移数据
     */
    public function migrateData(): void;

}
