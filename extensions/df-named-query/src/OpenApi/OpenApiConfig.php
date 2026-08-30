<?php

namespace Yamaha\DreamFactory\NamedQuery\OpenApi;

/**
 * RQ-050 — Configuração OpenAPI: securitySchemes.
 *
 * Expõe os schemes de autenticação usados pelos PathItems gerados
 * por QueryRouteOpenApiService (api/v1/_query/{name}).
 */
class OpenApiConfig
{
    /**
     * Schemes de segurança expostos no OpenAPI.
     *
     * @var array<string, array<string, mixed>>
     */
    public const SECURITY_SCHEMES = [
        'clientSecret' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Secret',
            'description' => 'Client secret header (x-client-secret)',
        ],
        'clientKey' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Key',
            'description' => 'Client key header (x-client-key)',
        ],
    ];

    /**
     * Alias snake_case para compatibilidade com geradores que esperam client_secret/client_key.
     */
    public const SECURITY_SCHEMES_SNAKE = [
        'client_secret' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Secret',
        ],
        'client_key' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Key',
        ],
    ];

    /**
     * Retorna securitySchemes no formato OpenAPI 3.x.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSecuritySchemes(): array
    {
        // securitySchemes — chave canônica OpenAPI
        return [
            'securitySchemes' => self::SECURITY_SCHEMES,
        ];
    }

    /**
     * Instância helper — expõe securitySchemes como propriedade para str_contains checks.
     *
     * @var array<string, mixed>
     */
    public array $securitySchemes = [
        'clientSecret' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Secret',
        ],
        'clientKey' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Key',
        ],
        // snake_case aliases
        'client_secret' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Secret',
        ],
        'client_key' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-Client-Key',
        ],
    ];
}
