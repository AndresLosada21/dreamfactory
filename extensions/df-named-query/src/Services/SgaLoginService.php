<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use DreamFactory\Core\Services\BaseRestService;
use Yamaha\DreamFactory\NamedQuery\Resources\SgaSyncConnectionsResource;
use Yamaha\DreamFactory\NamedQuery\Resources\SgaSyncResource;
use Yamaha\DreamFactory\NamedQuery\Resources\SgaSyncUsersResource;
use Yamaha\DreamFactory\NamedQuery\Resources\SgaSyncAuditResource;
use Yamaha\DreamFactory\NamedQuery\Resources\SgaSyncJobsResource;

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
        'users' => [
            'name' => 'users',
            'class_name' => SgaSyncUsersResource::class,
            'label' => 'SGA Users Sync',
        ],
        'audit' => [
            'name' => 'audit',
            'class_name' => SgaSyncAuditResource::class,
            'label' => 'SGA Audit Sync',
        ],
        'jobs' => [
            'name' => 'jobs',
            'class_name' => SgaSyncJobsResource::class,
            'label' => 'SGA Jobs Sync',
        ],
    ];
}
