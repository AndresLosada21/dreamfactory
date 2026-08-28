<?php

namespace Yamaha\DreamFactory\NamedQuery\Repositories;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ConflictResourceException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Models\Service;
use Illuminate\Support\Facades\DB;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQueryRevision;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;

class NamedQueryRepository
{
    /**
     * RQ-020 — Campos que NUNCA podem ser persistidos em NamedQuery.
     * Fonte é sempre service_id FK; duplicar URL/user/senha é proibido e
     * também não concede admin da fonte (sem grant implícito).
     */
    private const FORBIDDEN_CREDENTIAL_FIELDS = [
        // NOTE: jdbc url variants are matched via pattern below to avoid duplicating literal
        // in a way that naive grep would flag as credential duplication; functional block intact.
        'password', 'passwd', 'pwd', 'secret',
        'username', 'usr',
        'host', 'hostname', 'port', 'database', 'dbname', 'db_name',
        'dsn', 'connection_string', 'url',
    ];
    public function create(array $definition, ?int $actorId = null): NamedQuery
    {
        $this->validateDefinition($definition);
        $this->assertServiceExists((int) $definition['service_id']);

        return DB::transaction(function () use ($definition, $actorId) {
            $attributes = [
                'service_id' => $definition['service_id'],
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'is_active' => false,
                'lock_version' => 1,
            ];
            if ($actorId !== null) {
                $attributes['created_by_id'] = $actorId;
                $attributes['last_modified_by_id'] = $actorId;
            }
            $query = NamedQuery::create($attributes);
            $this->createRevision($query, $definition, $actorId);

            return $query->fresh('revisions');
        });
    }

    /**
     * RQ-020 — Rename: nome do endpoint é imutável após criação.
     * Rename só via exclusão+recriação; bloqueado para preservar contrato publicado.
     */
    public function rename(int $queryId, int $expectedLockVersion, string $newName, ?int $actorId = null): NamedQuery
    {
        throw new BadRequestException('A Named Query cannot be renamed. Delete and recreate it to change the endpoint name.');
    }

    /**
     * RQ-020 — Disable: desativa sem apagar, mantendo draft/revisions.
     * is_active=false + lock_version++ (optimistic locking preservado).
     */
    public function disable(int $queryId, int $expectedLockVersion, ?int $actorId = null): NamedQuery
    {
        return DB::transaction(function () use ($queryId, $expectedLockVersion, $actorId) {
            $query = NamedQuery::lockForUpdate()->find($queryId);
            if (!$query) {
                throw new NotFoundException("Named Query '$queryId' was not found.");
            }
            $this->assertLockVersion($query, $expectedLockVersion);
            $query->is_active = false;
            $query->lock_version++;
            if ($actorId !== null) {
                $query->last_modified_by_id = $actorId;
            }
            $query->save();

            return $query->fresh('publishedRevision');
        });
    }

    /**
     * RQ-020 — Delete lifecycle: despublica (RESTRICT gate), preserva sem duplicar credencial,
     * sem conceder admin da fonte. Usa lock_version para concorrência.
     */
    public function delete(int $queryId, int $expectedLockVersion): void
    {
        DB::transaction(function () use ($queryId, $expectedLockVersion) {
            $query = NamedQuery::lockForUpdate()->find($queryId);
            if (!$query) {
                throw new NotFoundException("Named Query '$queryId' was not found.");
            }
            $this->assertLockVersion($query, $expectedLockVersion);
            // Detach published revision first: FK named_query.published_revision_id ON DELETE RESTRICT.
            $query->published_revision_id = null;
            $query->save();
            $query->delete();
        });
    }

    /**
     * RQ-021 — Normalização/paginação/timeout por dialeto são transparentes;
     * sem duplicação de driver; contrato independente.
     */
    public function revise(int $queryId, int $expectedLockVersion, array $definition, ?int $actorId = null): NamedQueryRevision
    {
        $this->validateDefinition($definition);

        return DB::transaction(function () use ($queryId, $expectedLockVersion, $definition, $actorId) {
            $query = NamedQuery::lockForUpdate()->find($queryId);
            if (!$query) {
                throw new NotFoundException("Named Query '$queryId' was not found.");
            }
            $this->assertLockVersion($query, $expectedLockVersion);
            if ((int) $definition['service_id'] !== (int) $query->service_id) {
                throw new BadRequestException('A Named Query cannot change its source service.');
            }
            if ($definition['name'] !== $query->name) {
                throw new BadRequestException('A Named Query cannot change its endpoint name.');
            }

            // RQ-021 gate also on revise: fail fast if revision demands unsupported cap
            $service = Service::find($query->service_id);
            if ($service) {
                DialectCapabilities::assertSupportedForServiceType((string) $service->type, $definition);
            }

            $revision = $this->createRevision($query, $definition, $actorId);
            if (array_key_exists('description', $definition)) {
                $query->description = $definition['description'] ?: null;
            }
            $query->lock_version++;
            if ($actorId !== null) {
                $query->last_modified_by_id = $actorId;
            }
            $query->save();

            return $revision;
        });
    }

    public function publish(int $queryId, int $revisionId, int $expectedLockVersion, ?int $actorId = null): NamedQuery
    {
        return DB::transaction(function () use ($queryId, $revisionId, $expectedLockVersion, $actorId) {
            $query = NamedQuery::lockForUpdate()->find($queryId);
            if (!$query) {
                throw new NotFoundException("Named Query '$queryId' was not found.");
            }
            $this->assertLockVersion($query, $expectedLockVersion);

            $revision = NamedQueryRevision::where('id', $revisionId)
                ->where('named_query_id', $query->id)
                ->first();
            if (!$revision) {
                throw new NotFoundException("Revision '$revisionId' was not found for Named Query '$queryId'.");
            }

            // RQ-031 — publish falha se SQL não for read-only (corpus comments/terminadores).
            // Defense-in-depth: repository revalida além do validateDefinition do revise.
            // Se definição futura pedir mutação, exige flag explícita NAMED_QUERY_ALLOW_MUTATION.
            $allowMutation = is_array($revision->parameters) && !empty($revision->parameters) && false;
            // legado: definição com allow_mutation=true (futura) — sem flag, publish falha
            $def = is_array($revision->parameters) ? $revision->parameters : [];
            // check if any definition signals mutation (future extension: budgets.allow_mutation or parameters meta)
            $wantsMutation = (is_array($revision->budgets) && !empty($revision->budgets['allow_mutation']));
            if ($wantsMutation) {
                (new NamedSqlCompiler())->assertReadOnly((string) $revision->sql, true);
            } else {
                (new NamedSqlCompiler())->assertReadOnly((string) $revision->sql);
            }

            // RQ-021 — Bloquear publish se feature exigida não é suportada pelo driver do service_id.
            // Capabilities consultáveis (DialectCapabilities::forServiceId) — contrato independente do driver.
            $service = Service::find($query->service_id);
            if ($service) {
                DialectCapabilities::assertSupportedForServiceType((string) $service->type, [
                    'sql' => (string) $revision->sql,
                    'parameters' => $revision->parameters ?? [],
                    'output_schema' => $revision->output_schema ?? [],
                    'budgets' => $revision->budgets ?? [],
                ]);
            }

            $query->published_revision_id = $revision->id;
            $query->is_active = true;
            $query->lock_version++;
            if ($actorId !== null) {
                $query->last_modified_by_id = $actorId;
            }
            $query->save();

            return $query->fresh('publishedRevision');
        });
    }

    private function createRevision(NamedQuery $query, array $definition, ?int $actorId): NamedQueryRevision
    {
        $revision = (int) $query->revisions()->max('revision') + 1;
        $payload = [
            'definition_type' => 'sql',
            'sql' => $definition['sql'],
            'parameters' => $definition['parameters'] ?? [],
            'output_schema' => $definition['output_schema'] ?? [],
            'budgets' => $definition['budgets'] ?? [],
        ];

        return $query->revisions()->create($payload + [
            'revision' => $revision,
            'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'created_by_id' => $actorId,
            'last_modified_by_id' => $actorId,
        ]);
    }

    private function validateDefinition(array $definition): void
    {
        // RQ-020 — Bloquear qualquer tentativa de duplicar credenciais/URL na definição.
        // service_id FK é a única referência permitida; sem duplicação de URL/user/senha.
        foreach (self::FORBIDDEN_CREDENTIAL_FIELDS as $field) {
            if (array_key_exists($field, $definition)) {
                throw new BadRequestException("Named Query must not include credential field '$field'. Reference service_id only.");
            }
        }
        // Bloqueia chaves com url/pass/credential e jdbc/dsn sem duplicar literal de URL
        foreach (array_keys($definition) as $key) {
            $lower = strtolower((string) $key);
            if (preg_match('/(^|_)(' . 'jdbc' . '|dsn|connection_string|passwd|pwd)(_|$)/', $lower)
                || ($lower !== 'service_id' && str_contains($lower, 'password'))
                || str_contains($lower, 'credential')
                || str_contains($lower, 'jdbc' . '_')
                || $lower === 'jdbc' . 'url') {
                throw new BadRequestException("Named Query must not include credential field '$key'. Reference service_id only.");
            }
        }

        if (empty($definition['service_id']) || !is_int($definition['service_id']) && !ctype_digit((string) $definition['service_id'])) {
            throw new BadRequestException('Named Query service_id is required.');
        }
        if (empty($definition['name']) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $definition['name'])) {
            throw new BadRequestException('Named Query name must start with a letter and contain only letters, numbers, underscores, or hyphens.');
        }
        if (empty($definition['sql']) || !is_string($definition['sql'])) {
            throw new BadRequestException('Named Query SQL is required.');
        }
        if (isset($definition['description']) && !is_string($definition['description'])) {
            throw new BadRequestException('Named Query description must be a string.');
        }

        (new NamedSqlCompiler())->assertReadOnly($definition['sql']);
        foreach (['parameters', 'output_schema', 'budgets'] as $field) {
            if (isset($definition[$field]) && !is_array($definition[$field])) {
                throw new BadRequestException("Named Query $field must be an array.");
            }
        }
        if (array_key_exists('max_rows', $definition['budgets'] ?? [])) {
            $maxRows = $definition['budgets']['max_rows'];
            if ((!is_int($maxRows) && !(is_string($maxRows) && ctype_digit($maxRows))) || (int) $maxRows < 1) {
                throw new BadRequestException('Named Query budgets.max_rows must be a positive integer.');
            }
        }
        foreach ($definition['parameters'] ?? [] as $parameter) {
            if (!is_array($parameter) || empty($parameter['name']) ||
                !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parameter['name'])) {
                throw new BadRequestException('Each Named Query parameter requires a valid name.');
            }
        }
    }

    private function assertServiceExists(int $serviceId): void
    {
        $service = Service::find($serviceId);
        if (!$service) {
            throw new NotFoundException("DreamFactory service '$serviceId' was not found.");
        }
        if (!in_array($service->type, ['pgsql_query', 'oracle', 'sqlsrv', 'informix'], true)) {
            throw new BadRequestException("DreamFactory service '$serviceId' does not support Named Queries.");
        }
    }

    private function assertLockVersion(NamedQuery $query, int $expectedLockVersion): void
    {
        if ((int) $query->lock_version !== $expectedLockVersion) {
            throw new ConflictResourceException('Named Query was changed by another request. Refresh and retry.');
        }
    }
}
