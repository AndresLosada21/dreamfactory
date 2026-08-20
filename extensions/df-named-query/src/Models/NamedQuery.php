<?php

namespace Yamaha\DreamFactory\NamedQuery\Models;

use DreamFactory\Core\Models\BaseSystemModel;

class NamedQuery extends BaseSystemModel
{
    protected $table = 'named_query';

    protected $fillable = [
        'service_id',
        'name',
        'description',
        'is_active',
        'published_revision_id',
        'lock_version',
        'created_by_id',
        'last_modified_by_id',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'is_active' => 'boolean',
        'published_revision_id' => 'integer',
        'lock_version' => 'integer',
    ];

    protected $guarded = ['id', 'published_revision_id', 'lock_version'];

    public function revisions()
    {
        return $this->hasMany(NamedQueryRevision::class, 'named_query_id');
    }

    public function publishedRevision()
    {
        return $this->belongsTo(NamedQueryRevision::class, 'published_revision_id');
    }

    public function scopeForService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }
}
