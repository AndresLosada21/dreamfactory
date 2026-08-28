<?php

namespace Yamaha\DreamFactory\NamedQuery\Query;

use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities as ServiceDialectCapabilities;

/**
 * RQ-021 alias — contract re-export so both Services\ and Query\ namespaces work.
 * Canonical implementation lives in Services\DialectCapabilities.
 * Keeps contrato independente do driver and satisfies both candidate paths
 * checked by TddUltraSprint2Test::test_rq021_dialect_capabilities_queryable.
 */
class DialectCapabilities extends ServiceDialectCapabilities
{
}
