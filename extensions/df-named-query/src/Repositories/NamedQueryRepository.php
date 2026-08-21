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

class NamedQueryRepository
{
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
