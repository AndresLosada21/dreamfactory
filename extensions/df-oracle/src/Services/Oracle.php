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
}
