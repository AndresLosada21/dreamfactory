<?php

namespace Yamaha\DreamFactory\NamedQuery\Query;

class CompiledSql
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings
    ) {
    }
}
