<?php

namespace Yamaha\DreamFactory\Informix\Models;

use DreamFactory\Core\SqlDb\Models\SqlDbConfig;

class InformixConfig extends SqlDbConfig
{
    protected $appends = ['host', 'port', 'database', 'username', 'password', 'schema', 'server'];

    protected function getConnectionFields()
    {
        return ['host', 'port', 'database', 'username', 'password', 'schema', 'server'];
    }

    public static function getDriverName()
    {
        return 'informix';
    }

    public static function getDefaultPort()
    {
        return 9088;
    }

    public static function requireDriver($driver)
    {
        if (!extension_loaded('pdo_informix')) {
            throw new \Exception("Required PDO extension 'pdo_informix' is not installed or loaded.");
        }

        static::checkForPdoDriver('informix');

        return true;
    }
}
