<?php

namespace Yamaha\DreamFactory\Oracle\Models;

use DreamFactory\Core\SqlDb\Models\SqlDbConfig;

class OracleConfig extends SqlDbConfig
{
    protected $appends = ['host', 'port', 'database', 'username', 'password', 'schema', 'service_name', 'charset'];

    protected function getConnectionFields()
    {
        // RQ-023: service_name vs SID (database), schema, NUMBER/DATE/LOB handled in Schema,
        // charset for AL32UTF8, plus base fields.
        return ['host', 'port', 'database', 'username', 'password', 'schema', 'service_name', 'charset'];
    }

    public static function getDriverName()
    {
        return 'oracle';
    }

    public static function getDefaultPort()
    {
        return 1521;
    }

    public static function getDefaultConnectionInfo()
    {
        $defaults = parent::getDefaultConnectionInfo();
        $defaults[] = [
            'name' => 'service_name',
            'label' => 'Service Name',
            'type' => 'string',
            'description' => 'Oracle SERVICE_NAME (preferred over SID). Leave blank to use SID from Database field. See Oracle TNS docs.',
        ];
        $defaults[] = [
            'name' => 'charset',
            'label' => 'Charset',
            'type' => 'string',
            'description' => 'Oracle charset, e.g. AL32UTF8 (yajra/laravel-oci8 default).',
        ];
        return $defaults;
    }

    public static function requireDriver($driver)
    {
        if (!extension_loaded('oci8')) {
            throw new \Exception("Required extension 'oci8' is not installed or loaded. Install Oracle Instant Client (external dependency, not redistributed — see https://www.oracle.com/database/technologies/relevant-distribution-license.html and docs/architecture/connector-clean-room.md) and the oci8 PECL extension, then configure the service with host/service_name or SID.");
        }
        // yajra uses PDO via OCI8; no PDO driver check needed beyond oci8.
        return true;
    }
}
