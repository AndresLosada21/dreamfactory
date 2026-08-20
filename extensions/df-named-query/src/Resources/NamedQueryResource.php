<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\ResourcesWrapper;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;

class NamedQueryResource extends BaseRestResource
{
    public const RESOURCE_NAME = '_query';

    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function handleGET()
    {
        if (empty($this->resource)) {
            $this->checkPermission(Verbs::GET);

            $queries = NamedQuery::forService($this->getServiceId())
                ->where('is_active', true)
                ->whereNotNull('published_revision_id')
                ->orderBy('name')
                ->get(['name', 'description'])
                ->toArray();

            return ResourcesWrapper::cleanResources($queries, false, 'name');
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

        return $this->execute($payload);
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
        $rows = $this->parent->getConnection()->select($compiled->sql, $compiled->bindings);

        return ['resource' => array_map(fn ($row) => (array) $row, $rows)];
    }
}
