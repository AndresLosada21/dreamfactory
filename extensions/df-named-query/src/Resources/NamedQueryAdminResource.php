<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\System\Resources\BaseSystemResource;
use DreamFactory\Core\Utility\ResourcesWrapper;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Repositories\NamedQueryRepository;

class NamedQueryAdminResource extends BaseSystemResource
{
    protected static $model = NamedQuery::class;

    protected function handlePOST()
    {
        if (!empty($this->resource)) {
            throw new BadRequestException('Create a Named Query from the collection endpoint.');
        }

        $definition = $this->payload();
        $query = (new NamedQueryRepository())->create($definition);

        return $query->load('revisions')->toArray();
    }

    protected function handlePATCH()
    {
        if (empty($this->resource) || !ctype_digit((string) $this->resource)) {
            throw new NotFoundException('A Named Query identifier is required.');
        }

        $payload = $this->payload();
        if (!isset($payload['lock_version'])) {
            throw new BadRequestException('lock_version is required for Named Query updates.');
        }

        $repository = new NamedQueryRepository();
        if (isset($payload['publish_revision_id'])) {
            return $repository->publish(
                (int) $this->resource,
                (int) $payload['publish_revision_id'],
                (int) $payload['lock_version']
            )->toArray();
        }

        return $repository->revise(
            (int) $this->resource,
            (int) $payload['lock_version'],
            $payload
        )->toArray();
    }

    private function payload(): array
    {
        $payload = $this->getPayloadData();
        $records = ResourcesWrapper::unwrapResources($payload);
        if (is_array($records) && array_is_list($records)) {
            if (count($records) !== 1) {
                throw new BadRequestException('Exactly one Named Query definition is required.');
            }
            $payload = $records[0];
        }
        if (!is_array($payload)) {
            throw new BadRequestException('Named Query definition must be a JSON object.');
        }

        return $payload;
    }
}
