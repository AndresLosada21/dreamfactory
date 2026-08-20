<?php

namespace Yamaha\DreamFactory\NamedQuery\Models;

use DreamFactory\Core\Models\BaseSystemModel;

class NamedQueryRevision extends BaseSystemModel
{
    protected $table = 'named_query_revision';

    protected $fillable = [
        'named_query_id',
        'revision',
        'definition_type',
        'sql',
        'parameters',
        'output_schema',
        'budgets',
        'checksum',
        'created_by_id',
        'last_modified_by_id',
    ];

    protected $casts = [
        'named_query_id' => 'integer',
        'revision' => 'integer',
        'parameters' => 'array',
        'output_schema' => 'array',
        'budgets' => 'array',
        'created_by_id' => 'integer',
        'last_modified_by_id' => 'integer',
    ];

    public function namedQuery()
    {
        return $this->belongsTo(NamedQuery::class, 'named_query_id');
    }
}
