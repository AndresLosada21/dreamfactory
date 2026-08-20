<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use DreamFactory\Core\SqlDb\Services\PostgreSqlDb;

class QueryPostgreSql extends PostgreSqlDb
{
    use HasNamedQueryResource;
}
