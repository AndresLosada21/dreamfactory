<?php

namespace Yamaha\DreamFactory\SqlServer\Models;

use DreamFactory\Core\SqlDb\Models\SqlDbConfig;

class SqlServerConfig extends SqlDbConfig
{
    public static function getDriverName()
    {
        return 'sqlsrv';
    }

    public static function getDefaultPort()
    {
        return 1433;
    }
}
