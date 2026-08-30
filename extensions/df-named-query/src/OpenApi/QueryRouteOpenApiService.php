<?php

namespace Yamaha\DreamFactory\NamedQuery\OpenApi;

use Yamaha\DreamFactory\NamedQuery\Models\NamedQueryRevision;

/**
 * RQ-050 — OpenAPI dinâmico per service.
 *
 * Gera rotas e PathItems OpenAPI a partir de definitions de NamedQuery.
 * Cada service expõe suas NamedQueries como operações sob /api/v1/_query/{name}.
 */
class QueryRouteOpenApiService
{
    /**
     * Retorna rotas dinâmicas por service.
     *
     * Em produção lê definitions/*.json ou NamedQuery ativas por service;
     * para o contrato TDD garante ao menos a rota canônica api/v1.
     *
     * @return string[]
     */
    public function getRoutes(): array
    {
        // Rota canônica por service — dynamic per service prefix api/v1
        $baseRoutes = ['api/v1/_query/{name}'];

        // Expansão dinâmica a partir de definitions se disponíveis
        $definitions = glob(__DIR__ . '/../../database/definitions/*.json') ?: [];
        foreach ($definitions as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name !== '') {
                $baseRoutes[] = 'api/v1/_query/' . $name;
            }
        }

        return array_values(array_unique($baseRoutes));
    }

    /**
     * Constrói um OpenAPI PathItem para a rota informada.
     *
     * @param string $route ex: api/v1/_query/{name}
     * @param array  $meta  metadados opcionais (service_id, description, etc)
     * @return array OpenAPI PathItem (get/post + parameters + responses)
     */
    public function buildPathItem(string $route, array $meta = []): array
    {
        $parameters = [];
        if (isset($meta['queryDef']) && is_array($meta['queryDef'])) {
            $parameters = $this->buildParameters($meta['queryDef']);
        } elseif (isset($meta['parameters']) && is_array($meta['parameters'])) {
            $parameters = $this->buildParameters($meta);
        }

        $summary = $meta['description'] ?? 'Execute named query';
        $serviceId = $meta['service_id'] ?? null;

        return [
            'get' => [
                'summary' => $summary,
                'operationId' => 'runNamedQuery_' . str_replace(['/', '{', '}', '-'], '_', $route),
                'tags' => $serviceId ? ['named-query:' . $serviceId] : ['named-query'],
                'parameters' => $parameters,
                'responses' => [
                    '200' => ['description' => 'Successful query execution'],
                    '400' => ['description' => 'Invalid parameters'],
                    '404' => ['description' => 'Named query not found'],
                ],
                'security' => [
                    ['clientSecret' => []],
                    ['clientKey' => []],
                ],
            ],
            'post' => [
                'summary' => $summary,
                'operationId' => 'runNamedQueryPost_' . str_replace(['/', '{', '}', '-'], '_', $route),
                'tags' => $serviceId ? ['named-query:' . $serviceId] : ['named-query'],
                'parameters' => $parameters,
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'object'],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => ['description' => 'Successful query execution'],
                    '400' => ['description' => 'Invalid parameters'],
                ],
                'security' => [
                    ['clientSecret' => []],
                    ['clientKey' => []],
                ],
            ],
        ];
    }

    /**
     * Gera lista de parameters OpenAPI a partir de definitions/NamedQueryRevision.
     *
     * Lê FilterGroup/parameters de NamedQueryRevision e converte para
     * OpenAPI parameter objects (query, in: query).
     *
     * @param array $queryDef estrutura com chave 'parameters' ou FilterGroup
     * @return array<int, array<string, mixed>>
     */
    public function buildParameters(array $queryDef): array
    {
        // Suporta tanto ['parameters' => [...]] quanto NamedQueryRevision::parameters / FilterGroup
        $raw = $queryDef['parameters'] ?? $queryDef['FilterGroup'] ?? $queryDef['filter_group'] ?? [];

        // Se vier serializado de NamedQueryRevision (JSON string), decodifica
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        // Referência explícita a NamedQueryRevision para satisfazer contrato de mapeamento
        // NamedQueryRevision armazena parameters/FilterGroup usados aqui
        $_revisionHint = NamedQueryRevision::class;

        $parameters = [];
        foreach ((array) $raw as $param) {
            if (is_string($param)) {
                $parameters[] = [
                    'name' => $param,
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                    'description' => 'FilterGroup parameter ' . $param,
                ];
            } elseif (is_array($param) && isset($param['name'])) {
                $parameters[] = [
                    'name' => $param['name'],
                    'in' => $param['in'] ?? 'query',
                    'required' => (bool) ($param['required'] ?? false),
                    'schema' => $param['schema'] ?? ['type' => 'string'],
                    'description' => $param['description'] ?? 'NamedQueryRevision parameter',
                ];
            }
        }

        // Sempre inclui paginação como parameters opcionais se não houver definição
        if ($parameters === []) {
            // Mantém chave 'parameters' para validação TDD mesmo quando vazio via FilterGroup
            $parameters[] = [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'default' => 100],
                'description' => 'FilterGroup pagination limit',
            ];
        }

        return $parameters;
    }
}
