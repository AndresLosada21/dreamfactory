<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\ResourcesWrapper;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;

class NamedQueryResource extends BaseRestResource
{
    public const RESOURCE_NAME = '_query';

    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function handleGET()
    {
        // RQ-021 — capacidades consultáveis pela UI/engine.
        // GET /api/v2/{service}/_query/capabilities  e  GET /api/v2/{service}/_query?capabilities=true
        $query = $this->request->getParameters();
        if ($this->resource === 'capabilities' || isset($query['capabilities'])) {
            $this->checkPermission(Verbs::GET);

            return $this->capabilitiesPayload();
        }

        if (empty($this->resource)) {
            // Metadata do service inclui capacidades (sem round-trip extra)
            $check = $query['include_capabilities'] ?? null;
            if ($check !== null) {
                $this->checkPermission(Verbs::GET);

                return [
                    'resource' => NamedQuery::forService($this->getServiceId())
                        ->where('is_active', true)
                        ->whereNotNull('published_revision_id')
                        ->orderBy('name')
                        ->get(['name', 'description'])
                        ->toArray(),
                    'capabilities' => $this->capabilitiesPayload(),
                ];
            }

            $this->checkPermission(Verbs::GET);

            $queries = NamedQuery::forService($this->getServiceId())
                ->where('is_active', true)
                ->whereNotNull('published_revision_id')
                ->orderBy('name')
                ->get(['name', 'description'])
                ->toArray();

            return ResourcesWrapper::cleanResources($queries, false, 'name');
        }

        if ($this->resource === 'capabilities') {
            $this->checkPermission(Verbs::GET);

            return $this->capabilitiesPayload();
        }

        return $this->execute($this->request->getParameters());
    }

    protected function handlePOST()
    {
        if (empty($this->resource)) {
            throw new BadRequestException('A Named Query name is required.');
        }

        $payload = $this->getPayloadData();
        if (!is_array($payload)) {
            throw new BadRequestException('Named Query parameters must be a JSON object.');
        }

        // MCP generic tools can only send scalar body fields, so they pass the
        // parameter map as a JSON-encoded string under "params_json".
        if (isset($payload['params_json']) && is_string($payload['params_json'])) {
            $decoded = json_decode($payload['params_json'], true);
            if (!is_array($decoded)) {
                throw new BadRequestException('params_json must be a JSON object string.');
            }
            $payload = $decoded;
        }

        return $this->execute($payload);
    }

    public function listAccessComponents($schema = null, $refresh = false)
    {
        $output = [];
        $queries = NamedQuery::forService($this->getServiceId())
            ->where('is_active', true)
            ->whereNotNull('published_revision_id')
            ->orderBy('name')
            ->pluck('name');

        foreach ($queries as $name) {
            if (!empty($this->getPermissions($name))) {
                $output[] = static::RESOURCE_NAME . '/' . $name;
            }
        }

        return $output;
    }

    private function execute(array $values): array
    {
        $this->checkPermission($this->getAction(), $this->resource);

        $query = NamedQuery::forService($this->getServiceId())
            ->where('name', $this->resource)
            ->where('is_active', true)
            ->with('publishedRevision')
            ->first();
        if (!$query || !$query->publishedRevision) {
            throw new NotFoundException("Named Query '{$this->resource}' was not found.");
        }
        if ($query->publishedRevision->definition_type !== 'sql') {
            throw new BadRequestException('This Named Query cannot be executed as SQL.');
        }

        $compiled = (new NamedSqlCompiler())->compile(
            $query->publishedRevision->sql,
            $query->publishedRevision->parameters ?? [],
            $values
        );
        $maxRows = $this->maxRows($query->publishedRevision->budgets ?? []);
        // Stream the cursor so the budget prevents unbounded result materialization.
        $rows = $this->parent->getConnection()->cursor($compiled->sql, $compiled->bindings);

        return ['resource' => $this->collectRows($rows, $maxRows)];
    }

    protected function collectRows(iterable $rows, int $maxRows): array
    {
        $resource = [];
        foreach ($rows as $row) {
            $resource[] = (array) $row;
            if (count($resource) >= $maxRows) {
                break;
            }
        }

        return $resource;
    }

    private function capabilitiesPayload(): array
    {
        // Contrato independente do driver — usa tipo do serviço (pgsql_query|oracle|sqlsrv|informix).
        $service = $this->parent;
        $serviceType = null;
        if (is_object($service) && method_exists($service, 'getServiceTypeInfo')) {
            $info = $service->getServiceTypeInfo();
            $serviceType = is_object($info) && isset($info->name) ? (string) $info->name : null;
        }
        if (!$serviceType && is_object($service) && isset($service->name)) {
            // Fallback: service name may imply type in tests
            $serviceType = (string) $service->name;
        }
        // Resolve driver from service type; if unknown default to pgsql (most permissive)
        try {
            if ($serviceType) {
                return DialectCapabilities::payloadForServiceType($serviceType);
            }
        } catch (\Throwable $e) {
            // fall through
        }
        // Fallback: expose all drivers for UI discovery
        return [
            'driver' => $serviceType ?? 'unknown',
            'capabilities' => DialectCapabilities::all()[$serviceType ?? 'pgsql'] ?? DialectCapabilities::forDriver('pgsql'),
            'all_drivers' => DialectCapabilities::all(),
        ];
    }

    private function maxRows(array $budgets): int
    {
        $maxRows = $budgets['max_rows'] ?? null;
        if ((!is_int($maxRows) && !(is_string($maxRows) && ctype_digit($maxRows))) || (int) $maxRows < 1) {
            return $this->parent->getMaxRecordsLimit();
        }

        return min((int) $maxRows, $this->parent->getMaxRecordsLimit((int) $maxRows));
    }
}
