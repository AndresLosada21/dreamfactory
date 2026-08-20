<?php

namespace Yamaha\DreamFactory\Informix\Services;

use DreamFactory\Core\SqlDb\Services\SqlDb;
use Yamaha\DreamFactory\NamedQuery\Services\HasNamedQueryResource;

class Informix extends SqlDb
{
    use HasNamedQueryResource;

    public static function getDriverName()
    {
        return 'informix';
    }
}
