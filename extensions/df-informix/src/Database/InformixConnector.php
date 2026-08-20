<?php

namespace Yamaha\DreamFactory\Informix\Database;

use Illuminate\Database\Connection;

class InformixConnector
{
    public function connect(array $config)
    {
        if (!extension_loaded('pdo_informix')) {
            throw new \RuntimeException("Required PDO extension 'pdo_informix' is not installed or loaded.");
        }

        $required = ['host', 'database', 'server'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new \InvalidArgumentException("Informix connection requires '$field'.");
            }
        }

        $port = isset($config['port']) ? ';service=' . (int) $config['port'] : '';
        $dsn = sprintf(
            'informix:host=%s%s;database=%s;server=%s;protocol=onsoctcp',
            $config['host'],
            $port,
            $config['database'],
            $config['server']
        );
        $options = $config['options'] ?? [];
        $pdo = new \PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);

        return new Connection($pdo, $config['database'], $config['prefix'] ?? '', $config);
    }
}
