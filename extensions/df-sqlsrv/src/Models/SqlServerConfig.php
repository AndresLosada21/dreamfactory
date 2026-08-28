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

    /**
     * RQ-022: Secure defaults for pdo_sqlsrv / ODBC.
     * Encrypt is injected as a connection-string attribute (Encrypt=Yes)
     * rather than a driver option constant, so it is visible in config
     * and auditable via tests without requiring the binary.
     */
    public static function adaptConfig(array &$config)
    {
        parent::adaptConfig($config);

        $config['driver'] = static::getDriverName();

        // Do not override an explicit user choice; default to encrypted.
        if (!array_key_exists('Encrypt', $config) && !array_key_exists('encrypt', $config)) {
            $config['Encrypt'] = 'Yes';
        }
        if (!array_key_exists('TrustServerCertificate', $config) && !array_key_exists('trust_server_certificate', $config)) {
            // Homologation environments use self-signed certs; production
            // should set TrustServerCertificate=No and provide a CA.
            $config['TrustServerCertificate'] = 'Yes';
        }
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
