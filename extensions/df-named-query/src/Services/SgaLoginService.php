<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use DreamFactory\Core\Services\BaseRestService;
use Yamaha\DreamFactory\NamedQuery\Resources\SgaSyncConnectionsResource;
use Yamaha\DreamFactory\NamedQuery\Resources\SgaSyncResource;

/**
 * E10 — servico publico de login via SGA (sem chave de API).
 * POST /api/v2/sga_login/sync espelha conta/papel no DF.
 * Excecao de acesso registrada no tipo do servico (como user/session).
 */
class SgaLoginService extends BaseRestService
{
    protected static $resources = [
        'sync' => [
            'name' => 'sync',
            'class_name' => SgaSyncResource::class,
            'label' => 'SGA Sync Login',
        ],
        'connections' => [
            'name' => 'connections',
            'class_name' => SgaSyncConnectionsResource::class,
            'label' => 'SGA Database Sync',
        ],
    ];
}
