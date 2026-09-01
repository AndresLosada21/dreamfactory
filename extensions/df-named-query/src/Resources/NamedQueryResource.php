<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\Session;
use Yamaha\DreamFactory\NamedQuery\Http\EnvelopeTranslator;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Query\JsonQueryCompiler;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;
use Yamaha\DreamFactory\NamedQuery\Query\QueryExecutionBudget;
use Yamaha\DreamFactory\NamedQuery\Services\ClusterInvalidationService;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;
use Yamaha\DreamFactory\NamedQuery\Services\NamedQueryAudit;

class NamedQueryResource extends BaseRestResource
{
    public const RESOURCE_NAME = '_query';

    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function handleGET()
    {
        // RQ-070 — stateless read: verifica generation para detectar stale cache (sem reter estado local)
        try { (new ClusterInvalidationService())->getGeneration(); } catch (\Throwable $ignored) {}
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

                $queries = NamedQuery::forService($this->getServiceId())
                    ->where('is_active', true)
                    ->whereNotNull('published_revision_id')
                    ->orderBy('name')
                    ->get(['name', 'description'])
                    ->toArray();
                // RQ-040 — discovery não revela sem permissão: filtra por componente concreto _query/{name}
                // via RBAC nativo (Session::getServicePermissions -> getPermissions(name)).
                $queries = array_values(array_filter($queries, fn(array $q) => !empty($this->getPermissions($q['name']))));

                return [
                    'resource' => $queries,
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
            // RQ-040 — filtra listagem por permissão concreta _query/{name} (RBAC nativo).
            // listAccessComponents() é a fonte canônica; aqui replica filtro para resposta direta
            // sem bypass paralelo. Reutiliza Session::getServicePermissions via getPermissions(name).
            $queries = array_values(array_filter($queries, fn(array $q) => !empty($this->getPermissions($q['name']))));

            return ResourcesWrapper::cleanResources($queries, false, 'name');
        }

        if ($this->resource === 'capabilities') {
            $this->checkPermission(Verbs::GET);

            return $this->capabilitiesPayload();
        }

        // RQ-044 — envelope legado opt-in via ?envelope=legacy ou header X-Legacy-Envelope
        $values = $this->request->getParameters();
        if (EnvelopeTranslator::isLegacyRequested($this->request)) {
            $start = microtime(true);
            $filtered = $values;
            unset($filtered['envelope'], $filtered['capabilities'], $filtered['include_capabilities']);
            try {
                $native = $this->execute($filtered);
                $resource = $native['resource'] ?? [];
                return EnvelopeTranslator::toLegacySuccess(is_array($resource) ? $resource : [], $start);
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        $filtered = $values;
        unset($filtered['envelope']);
        return $this->execute($filtered);
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

        // RQ-044 — extrai opt legado de params internos (envelope=legacy) antes de executar
        $envelopeParam = $payload['envelope'] ?? null;
        $bodyEnvelopeLegacy = $envelopeParam !== null && strtolower(trim((string) $envelopeParam)) === 'legacy';
        if (isset($payload['envelope'])) {
            unset($payload['envelope']);
        }
        $isLegacy = $bodyEnvelopeLegacy || EnvelopeTranslator::isLegacyRequested($this->request);
        if ($isLegacy) {
            $start = microtime(true);
            $native = $this->execute($payload);
            $resource = $native['resource'] ?? [];
            return EnvelopeTranslator::toLegacySuccess(is_array($resource) ? $resource : [], $start);
        }

        return $this->execute($payload);
    }

    /**
     * RQ-044 — Intercepta respostas para traduzir envelope quando legado solicitado.
     * Preserva contrato nativo por padrão; só traduz quando opt-in.
     * Reutiliza RBAC/events nativos sem autorização paralela — delegate para parent.
     */
    public function handleRequest(\DreamFactory\Core\Contracts\ServiceRequestInterface $request, $resource = null)
    {
        // Se não é execução de query concreta (lista/capabilities), delega direto
        // Para execução concreta, intercepta erros para traduzir para envelope legado
        $response = parent::handleRequest($request, $resource);

        // Verifica se foi erro e se é pedido legado — traduz payload de erro
        try {
            $isLegacy = EnvelopeTranslator::isLegacyRequested($request);
            if (!$isLegacy) {
                return $response;
            }

            // Response é ServiceResponse com status >=400 => traduz
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 0;
            if ($status >= 400) {
                $content = method_exists($response, 'getContent') ? $response->getContent() : null;
                if (is_array($content)) {
                    // Já é legacy? Não re-traduz
                    if (isset($content['erroCode'])) {
                        return $response;
                    }
                    // DF nativo: {error:{code,message,context,status_code}}
                    $dfError = $content['error'] ?? $content;
                    $code = 500;
                    $message = '';
                    if (is_array($dfError)) {
                        $code = (int) ($dfError['code'] ?? $dfError['status_code'] ?? $status);
                        $message = (string) ($dfError['message'] ?? $dfError['errorMessage'] ?? '');
                    } elseif (is_string($dfError)) {
                        $message = $dfError;
                    }
                    // Usa status HTTP como fonte primária para erroCode
                    $legacy = EnvelopeTranslator::toLegacyErrorFromStatusAndMessage($status, $message);
                    // Se DF já trouxe code como erroCode, corrige mapeamento se HTTP for mais confiável
                    if ($status === 0 && $code >= 400) {
                        $legacy = EnvelopeTranslator::toLegacyErrorFromStatusAndMessage($code, $message);
                    }
                    // Substitui conteúdo mantendo status
                    if (method_exists($response, 'setContent')) {
                        $response->setContent($legacy);
                    } else {
                        // Reconstrói ServiceResponse
                        $response = new \DreamFactory\Core\Utility\ServiceResponse($legacy, null, $status);
                    }
                }
            }
        } catch (\Throwable $ignored) {
            // Não quebra pipeline nativo
        }

        return $response;
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

    /**
     * RQ-013 — Eventos nativos de _query: pre/post/final existem via pipeline DF.
     * Este resource estende BaseRestResource, entao o pipeline nativo (RestHandler)
     * dispara: PreProcessApiEvent, PostProcessApiEvent e ApiEvent (final) automaticamente
     * em handleRequest() (preProcess/processRequest/postProcess/respond -> fireFinalEvent).
     * Nao ha dispatch manual aqui para evitar duplicacao; o event name e
     * {service}._query[.path].{verb} e e exposto via getEventMap().
     */
    public function getEventMap()
    {
        // Gera eventos nativos a partir do OpenAPI deste resource.
        // Isso garante que pre/post/final aparecam no admin de eventos do DF.
        $events = [];
        $service = $this->getServiceName();
        $base = $service . '.' . static::RESOURCE_NAME;
        $endpoints = [
            $base . '.get',
            $base . '.post',
            $base . '.put',
            $base . '.patch',
            $base . '.delete',
            $base . '.capabilities.get',
        ];
        // path-level entry for each resource+verb
        foreach (array_unique([$base, $base . '.capabilities']) as $path) {
            $type = $path === $base . '.capabilities' ? $base . '.capabilities' : $base;
            $eps = array_filter($endpoints, static function ($e) use ($path) {
                return $e === $path . '.get' || $e === $path . '.post'
                    || $e === $path . '.put' || $e === $path . '.patch' || $e === $path . '.delete'
                    || strpos($e, $path . '.') === 0;
            });
            if ($path === $base) {
                $eps = [$base . '.get', $base . '.post'];
            }
            $events[$path] = [
                'type' => 'api',
                'endpoints' => array_values($eps ?: [$path . '.get']),
                'parameter' => null,
            ];
        }
        // Also expose per-named-query parameterization via common pattern
        // (access list already provides _query/{name} components; eventos sao por resource)
        return $events;
    }

    protected function getApiDocPaths()
    {
        $wrapper = \DreamFactory\Core\Utility\ResourcesWrapper::getWrapper();
        return [
            '/' . static::RESOURCE_NAME => [
                'get' => [
                    'summary' => 'List published Named Queries.',
                    'description' => 'Returns only active queries with a published revision.',
                    'operationId' => 'getNamedQueries',
                    'responses' => ['200' => ['$ref' => '#/components/responses/NamedQueryResourceResponse']],
                ],
            ],
            '/' . static::RESOURCE_NAME . '/{name}' => [
                'get' => [
                    'summary' => 'Execute a Named Query.',
                    'operationId' => 'executeNamedQuery',
                    'parameters' => [[
                        'name' => 'name', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'string'],
                    ]],
                    'responses' => ['200' => ['$ref' => '#/components/responses/NamedQueryResourceResponse']],
                ],
                'post' => [
                    'summary' => 'Execute a Named Query (POST).',
                    'operationId' => 'executeNamedQueryPost',
                    'parameters' => [[
                        'name' => 'name', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'string'],
                    ]],
                    'requestBody' => ['$ref' => '#/components/requestBodies/NamedQueryResourceRequest'],
                    'responses' => ['200' => ['$ref' => '#/components/responses/NamedQueryResourceResponse']],
                ],
            ],
            '/' . static::RESOURCE_NAME . '/capabilities' => [
                'get' => [
                    'summary' => 'Get dialect capabilities.',
                    'operationId' => 'getNamedQueryCapabilities',
                    'responses' => ['200' => ['$ref' => '#/components/responses/NamedQueryResourceResponse']],
                ],
            ],
        ];
    }

    private function execute(array $values): array
    {
        // RQ-070 — stateless: sem cache local retido; verifica generation antes de servir
        try { (new ClusterInvalidationService())->getGeneration(); } catch (\Throwable $ignored) {}
        // RQ-040 — execução exige permissão no componente concreto _query/{name}
        // via RBAC nativo Session::checkServicePermission (sem autorização paralela).
        // Chamadas internas (Repository/Audit) seguem policy explícita do request; não criam bypass.
        $this->checkPermission($this->getAction(), $this->resource);
        $start = microtime(true);
        $query = null;

        try {
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
            // RQ-041 — budgets hierárquicos cluster-safe: DB read direto (sem cache local retido), min(budgets.max_rows, parent->getMaxRecordsLimit())
            $budgets = array_merge(JsonQueryCompiler::DEFAULT_BUDGETS, $query->publishedRevision->budgets ?? []);
            $maxRows = $this->maxRows($budgets);
            $budget = new QueryExecutionBudget($budgets, $start);
            // Deadline reduz timeout de statements: PDO::ATTR_TIMEOUT + statement_timeout
            $budget->checkDeadline();
            $budget->applyToConnection($this->parent->getConnection(), (int) $budgets['query_timeout_seconds']);

            // Stream the cursor so the budget prevents unbounded result materialization.
            $rows = $this->parent->getConnection()->cursor($compiled->sql, $compiled->bindings);
            $resource = $this->collectRows($rows, $maxRows, $budget);

            NamedQueryAudit::recordWithDuration('execute', [
                'actor_id' => Session::getCurrentUserId(),
                'service_id' => (int) $this->getServiceId(),
                'query_id' => (int) $query->id,
                'query_name' => (string) $query->name,
                'revision' => (int) ($query->publishedRevision->revision ?? 0),
                'revision_id' => (int) ($query->publishedRevision->id ?? 0),
                'checksum' => (string) ($query->publishedRevision->checksum ?? ''),
                'budgets' => $query->publishedRevision->budgets ?? null,
            ], $start, 'success');

            return ['resource' => $resource];
        } catch (\Throwable $e) {
            // Audita falha sem logar SQL/bind/secret — apenas checksum/budgets + error_code
            $checksum = null; $budgets = null; $qid = null; $rev = null; $revId = null; $qname = (string) $this->resource;
            if ($query && $query->publishedRevision) {
                $checksum = (string) ($query->publishedRevision->checksum ?? '');
                $budgets = $query->publishedRevision->budgets ?? null;
                $qid = (int) $query->id;
                $rev = (int) ($query->publishedRevision->revision ?? 0);
                $revId = (int) ($query->publishedRevision->id ?? 0);
                $qname = (string) $query->name;
            } else {
                try {
                    $tmp = NamedQuery::forService($this->getServiceId())->where('name', $this->resource)->with('publishedRevision')->first();
                    if ($tmp) {
                        $qid = (int) $tmp->id;
                        $qname = (string) $tmp->name;
                        if ($tmp->publishedRevision) {
                            $checksum = (string) ($tmp->publishedRevision->checksum ?? '');
                            $budgets = $tmp->publishedRevision->budgets ?? null;
                            $rev = (int) ($tmp->publishedRevision->revision ?? 0);
                            $revId = (int) ($tmp->publishedRevision->id ?? 0);
                        }
                    }
                } catch (\Throwable $ignored) {
                }
            }
            NamedQueryAudit::recordWithDuration('execute', [
                'actor_id' => Session::getCurrentUserId(),
                'service_id' => (int) $this->getServiceId(),
                'query_id' => $qid,
                'query_name' => $qname,
                'revision' => $rev,
                'revision_id' => $revId,
                'checksum' => $checksum,
                'budgets' => $budgets,
            ], $start, 'failure', get_class($e));

            throw $e;
        }
    }

    protected function collectRows(iterable $rows, int $maxRows, ?QueryExecutionBudget $budget = null): array
    {
        $resource = [];
        foreach ($rows as $row) {
            $arr = (array) $row;
            if ($budget !== null) {
                $budget->checkDeadline();
                $budget->acceptRow($arr);
            }
            $resource[] = $arr;
            if (count($resource) >= $maxRows) {
                break;
            }
        }
        if ($budget !== null) {
            $budget->verifyFinalBody($resource);
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
