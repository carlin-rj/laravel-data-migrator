<?php

namespace Carlin\DataMigrator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Carlin\DataMigrator\DataMigrator
 */
class DataMigrator extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'data-migrator';
    }
}
