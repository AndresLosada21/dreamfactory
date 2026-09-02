<?php

namespace Yamaha\DreamFactory\PremiumStub\Resources;

use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\ResourcesWrapper;

/**
 * Generic mock for premium System Resources — returns empty collections
 * so frontend paywall-resolved pages render without 404.
 * GOLD unlock via Determinus — no DB table required.
 */
class MockPremiumResource extends BaseRestResource
{
    protected static function getResourceIdentifier()
    {
        return 'id';
    }

    protected function handleGET()
    {
        // Single resource by id — return stub or 404-like empty
        if (!empty($this->resource)) {
            // For limit_cache_by_limit_id etc, return empty wrapper
            if (str_contains($this->resource, '_by_')) {
                return ResourcesWrapper::wrapResources([], null);
            }
            // Single id — return stub object with id
            return ['id' => $this->resource, 'name' => $this->resource, 'is_mock' => true];
        }
        // Collection — empty list
        $data = ResourcesWrapper::wrapResources([], null);
        // Ensure meta count if requested
        return $data;
    }

    protected function handlePOST()
    {
        $payload = $this->getPayloadData();
        $resources = ResourcesWrapper::unwrapResources($payload);
        if (empty($resources)) {
            $resources = [$payload];
        }
        $wrapped = [];
        foreach ($resources as $r) {
            $r = is_array($r) ? $r : [];
            if (!isset($r['id'])) {
                $r['id'] = random_int(1, 9999);
            }
            $wrapped[] = $r;
        }
        return ResourcesWrapper::wrapResources($wrapped, null);
    }

    protected function handlePUT()
    {
        return $this->handlePOST();
    }

    protected function handlePATCH()
    {
        return $this->handleGET();
    }

    protected function handleDELETE()
    {
        return ['success' => true];
    }

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $cap = \camelize($service);
        $rn = strtolower($this->name);
        $path = '/' . $rn;
        return [
            $path => [
                'get' => [
                    'summary' => 'List ' . $this->name . ' (mock premium stub)',
                    'description' => 'Premium stub — returns empty collection (Determinus GOLD).',
                    'operationId' => 'get' . $cap . ucfirst($rn),
                    'responses' => ['200' => ['$ref' => '#/components/responses/Success']],
                ],
                'post' => [
                    'summary' => 'Create ' . $this->name,
                    'operationId' => 'create' . $cap . ucfirst($rn),
                    'responses' => ['200' => ['$ref' => '#/components/responses/Success']],
                ],
            ],
            $path . '/{id}' => [
                'get' => [
                    'summary' => 'Get ' . $this->name . ' by id',
                    'operationId' => 'get' . $cap . ucfirst($rn) . 'ById',
                    'parameters' => [[
                        'name' => 'id', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'string']
                    ]],
                    'responses' => ['200' => ['$ref' => '#/components/responses/Success']],
                ],
                'put' => ['summary' => 'Update', 'operationId' => 'update' . $cap . ucfirst($rn), 'responses' => ['200' => ['$ref' => '#/components/responses/Success']]],
                'patch' => ['summary' => 'Patch', 'operationId' => 'patch' . $cap . ucfirst($rn), 'responses' => ['200' => ['$ref' => '#/components/responses/Success']]],
                'delete' => ['summary' => 'Delete', 'operationId' => 'delete' . $cap . ucfirst($rn), 'responses' => ['200' => ['$ref' => '#/components/responses/Success']]],
            ],
        ];
    }
}
