<?php

namespace Yamaha\DreamFactory\SqlServer\Services;

use DreamFactory\Core\SqlDb\Services\SqlDb;
use Yamaha\DreamFactory\NamedQuery\Services\HasNamedQueryResource;
use Yamaha\DreamFactory\SqlServer\Models\SqlServerConfig;

class SqlServer extends SqlDb
{
    use HasNamedQueryResource;

    public static function getDriverName()
    {
        return 'sqlsrv';
    }

    public static function adaptConfig(array &$config)
    {
        SqlServerConfig::adaptConfig($config);
    }
}
