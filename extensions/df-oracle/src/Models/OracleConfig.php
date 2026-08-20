<?php

namespace Yamaha\DreamFactory\Oracle\Models;

use DreamFactory\Core\SqlDb\Models\SqlDbConfig;

class OracleConfig extends SqlDbConfig
{
    public static function getDriverName()
    {
        return 'oracle';
    }

    public static function getDefaultPort()
    {
        return 1521;
    }
}
