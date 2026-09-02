<?php

namespace Yamaha\DreamFactory\SqlServer\Services;

use DreamFactory\Core\SqlDb\Services\SqlDb;
use Yamaha\DreamFactory\NamedQuery\Services\HasNamedQueryResource;

class SqlServer extends SqlDb
{
    use HasNamedQueryResource;

    public static function getDriverName()
    {
        return 'sqlsrv';
    }

    public static function adaptConfig(array &$config)
    {
        if (!isset($config['driver'])) {
            $config['driver'] = static::getDriverName();
        }
        parent::adaptConfig($config);

        // RQ-022: Secure defaults for pdo_sqlsrv / ODBC. Encrypt is injected as
        // connection-string attribute (Encrypt=Yes) rather than driver option constant,
        // so it is visible in config and auditable via tests without requiring binary.
        if (!array_key_exists('Encrypt', $config) && !array_key_exists('encrypt', $config)) {
            $config['Encrypt'] = 'Yes';
        }
        if (!array_key_exists('TrustServerCertificate', $config) && !array_key_exists('trust_server_certificate', $config)) {
            // Homologation environments use self-signed certs; production
            // should set TrustServerCertificate=No and provide a CA.
            $config['TrustServerCertificate'] = 'Yes';
        }
    }
}
