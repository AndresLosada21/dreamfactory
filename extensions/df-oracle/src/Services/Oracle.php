<?php

namespace Yamaha\DreamFactory\Oracle\Services;

use DreamFactory\Core\SqlDb\Services\SqlDb;
use Yamaha\DreamFactory\NamedQuery\Services\HasNamedQueryResource;

class Oracle extends SqlDb
{
    use HasNamedQueryResource;

    public static function getDriverName()
    {
        return 'oracle';
    }

    public static function adaptConfig(array &$config)
    {
        if (!isset($config['driver'])) {
            $config['driver'] = static::getDriverName();
        }
        // RQ-023: yajra expects 'oracle' driver; service_name vs database (SID) handled by OracleConnector.
        // Preserve service_name if provided; do not overwrite an explicit tns.
        parent::adaptConfig($config);
    }
}
