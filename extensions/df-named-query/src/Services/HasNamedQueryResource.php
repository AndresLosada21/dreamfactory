<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use Yamaha\DreamFactory\NamedQuery\Resources\NamedQueryResource;

trait HasNamedQueryResource
{
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
}
