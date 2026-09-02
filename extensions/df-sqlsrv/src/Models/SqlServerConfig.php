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

    public static function requireDriver($driver)
    {
        // Prefer pdo_sqlsrv; fall back to dblib only if explicitly requested.
        // Do not silently downgrade — surface a precise error.
        if ($driver === 'sqlsrv') {
            if (!extension_loaded('pdo_sqlsrv') && !extension_loaded('sqlsrv')) {
                throw new \Exception("Required extension 'pdo_sqlsrv' (Microsoft ODBC Driver for SQL Server) is not installed. Install the Microsoft ODBC Driver (msodbcsql) and the pdo_sqlsrv PECL extension — see https://learn.microsoft.com/en-us/sql/connect/php/installation-tutorial-linux-mac — and set ACCEPT_EULA=Y at build time. The ODBC driver is an external dependency and is not redistributed with this package.");
            }
            static::checkForPdoDriver('sqlsrv');
            return true;
        }

        return parent::requireDriver($driver);
    }
}
