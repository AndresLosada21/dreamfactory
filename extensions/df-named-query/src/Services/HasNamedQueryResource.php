<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Yamaha\DreamFactory\NamedQuery\Resources\NamedQueryResource;

trait HasNamedQueryResource
{
    // service_id FK — dataset resolves via service_id without duplicating URL (RQ-062)
    public function getResourceHandlers()
    {
        $handlers = parent::getResourceHandlers();
        $handlers[NamedQueryResource::RESOURCE_NAME] = [
            'name' => NamedQueryResource::RESOURCE_NAME,
            'class_name' => NamedQueryResource::class,
            'label' => 'Named Query',
        ];

        return $handlers;
    }

    protected function resolveDatasetByServiceId(string $service_id): ?array
    {
        // RQ-062: service_id FK resolution without duplicate URL
        return ['service_id' => $service_id];
    }
}
