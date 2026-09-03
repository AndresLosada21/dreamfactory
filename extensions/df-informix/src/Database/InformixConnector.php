<?php

namespace Yamaha\DreamFactory\Informix\Database;

use Illuminate\Database\Connection;

class InformixConnector
{
    public function connect(array $config)
    {
        if (!extension_loaded('pdo_informix')) {
            // RQ-024: fail fast with explicit message; CSDK/libifcli.so is external, never vendored.
            // See docs/architecture/connector-clean-room.md §10 and Dockerfile.offline:1.
            throw new \RuntimeException("Required PDO extension 'pdo_informix' is not installed or loaded. Install PDO_INFORMIX (PECL, see docker/vendor/PDO_INFORMIX-1.3.7.tgz) against an entitled IBM Informix CSDK (libifcli.so) — CSDK is not redistributed with this repo. PHP 8.3 + Laravel 13 required.");
        }

        $required = ['host', 'database', 'server'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new \InvalidArgumentException("Informix connection requires '$field'.");
            }
        }

        $port = isset($config['port']) ? ';SERVICE=' . (int) $config['port'] : '';
        $dsn = sprintf(
            'informix:DRIVER={Informix};SERVER=%s;HOST=%s%s;PROTOCOL=onsoctcp;DATABASE=%s',
            $config['server'],
            $config['host'],
            $port,
            $config['database']
        );
        $options = $config['options'] ?? [];
        // RQ-024: LVARCHAR/TEXT/BYTE are handled via PDO type mapping in InformixSchema; transaction semantics via PDO.
        $pdo = new \PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        // Owner-scoped schema is preserved in InformixSchema::getSchemas()/loadTableColumns via tabowner.

        return new Connection($pdo, $config['database'], $config['prefix'] ?? '', $config);
    }
}
