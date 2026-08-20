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
}
