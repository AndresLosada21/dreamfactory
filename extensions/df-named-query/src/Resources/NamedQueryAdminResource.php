<?php

namespace Yamaha\DreamFactory\NamedQuery\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\System\Resources\BaseSystemResource;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\Session;
use Yamaha\DreamFactory\NamedQuery\Models\NamedQuery;
use Yamaha\DreamFactory\NamedQuery\Repositories\NamedQueryRepository;

class NamedQueryAdminResource extends BaseSystemResource
{
    protected static $model = NamedQuery::class;

    protected function handleGET()
    {
        if (!empty($this->resource)) {
            $query = NamedQuery::with('revisions')->find($this->resource);
            if (!$query) {
                throw new NotFoundException("Named Query '{$this->resource}' was not found.");
            }

            return $query->toArray();
        }

        $queries = NamedQuery::query()
            ->orderBy('service_id')
            ->orderBy('name')
            ->get(['id', 'service_id', 'name', 'description', 'is_active', 'published_revision_id', 'lock_version'])
            ->toArray();

        return ResourcesWrapper::cleanResources($queries);
    }

    protected function handlePOST()
    {
        if (!empty($this->resource)) {
            throw new BadRequestException('Create a Named Query from the collection endpoint.');
        }

        $definition = $this->payload();
        $query = (new NamedQueryRepository())->create($definition, $this->actorId());

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
                (int) $payload['lock_version'],
                $this->actorId()
            )->toArray();
        }

        return $repository->revise(
            (int) $this->resource,
            (int) $payload['lock_version'],
            $payload,
            $this->actorId()
        )->toArray();
    }

    protected function handleDELETE()
    {
        if (empty($this->resource) || !ctype_digit((string) $this->resource)) {
            throw new NotFoundException('A Named Query identifier is required.');
        }

        $query = NamedQuery::find($this->resource);
        if (!$query) {
            throw new NotFoundException("Named Query '{$this->resource}' was not found.");
        }

        // Detach the published revision first: the FK from named_query to
        // named_query_revision is ON DELETE RESTRICT and would block the delete.
        $query->published_revision_id = null;
        $query->save();
        $query->delete();

        return ['success' => true];
    }

    private function payload(): array
    {
        $payload = $this->getPayloadData();
        $wrapper = ResourcesWrapper::getWrapper();
        if (is_array($payload) && (array_key_exists($wrapper, $payload) || isset($payload[0]))) {
            $records = ResourcesWrapper::unwrapResources($payload);
            if (!is_array($records) || !array_is_list($records) || count($records) !== 1) {
                throw new BadRequestException('Exactly one Named Query definition is required.');
            }
            $payload = $records[0];
        }
        if (!is_array($payload)) {
            throw new BadRequestException('Named Query definition must be a JSON object.');
        }

        // MCP generic tools can only send scalar body fields, so they pass the
        // whole definition as a JSON-encoded string under "definition_json".
        if (isset($payload['definition_json']) && is_string($payload['definition_json'])) {
            $decoded = json_decode($payload['definition_json'], true);
            if (!is_array($decoded)) {
                throw new BadRequestException('definition_json must be a JSON object string.');
            }
            unset($payload['definition_json']);
            $payload = array_merge($payload, $decoded);
        }

        // Allow referencing the source service by name (agent-friendly).
        if (empty($payload['service_id']) && !empty($payload['service_name'])) {
            $service = Service::where('name', $payload['service_name'])->first();
            if (!$service) {
                throw new NotFoundException("DreamFactory service '{$payload['service_name']}' was not found.");
            }
            $payload['service_id'] = $service->id;
        }
        unset($payload['service_name']);

        return $payload;
    }

    private function actorId(): ?int
    {
        $userId = Session::getCurrentUserId();

        return $userId === null ? null : (int) $userId;
    }
}
