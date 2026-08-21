<?php
/**
 * Registers the generic Named Query MCP custom tools on the "vfdf" MCP service.
 *
 * Usage (inside the dreamfactory container):
 *   php /opt/dreamfactory/scripts/mcp-named-query-tools.php
 *
 * Idempotent: upserts by (service_id, name); never touches other tools.
 */

require '/opt/dreamfactory/vendor/autoload.php';
$app = require '/opt/dreamfactory/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use DreamFactory\Core\McpServer\Models\McpCustomTool;
use Illuminate\Support\Facades\DB;

$serviceName = 'yamaha-query';
$service = DB::table('service')->where('name', $serviceName)->first();
if (!$service) {
    fwrite(STDERR, "Service '$serviceName' not found.\n");
    exit(1);
}
$serviceId = (int) $service->id;

$base = 'http://127.0.0.1/api/v2';

$tools = [
    [
        'name' => 'named_query_list_published',
        'description' => 'List published (executable) Named Queries for a database service. Returns name and description of each. Use this to discover ready-made queries before calling named_query_run.',
        'http_method' => 'GET',
        'url' => $base . '/{service}/_query',
        'parameters' => [
            ['name' => 'service', 'type' => 'string', 'in' => 'path', 'required' => true, 'description' => 'Database service name (e.g. py_ptg, gq_mi_wms, gq_eficaz, py_local)'],
        ],
    ],
    [
        'name' => 'named_query_run',
        'description' => 'Execute a published Named Query by name on a database service. SQL is read-only server-side (SELECT/WITH only, no semicolons). Pass query parameters as a JSON object string in params_json, e.g. "{\"inicio\":\"2026-01-01\",\"fim\":\"2026-12-31\"}". Errors: 404 = query not published (check with named_query_list_all); 400 names the missing/invalid parameter.',
        'http_method' => 'POST',
        'url' => $base . '/{service}/_query/{name}',
        'parameters' => [
            ['name' => 'service', 'type' => 'string', 'in' => 'path', 'required' => true, 'description' => 'Database service name'],
            ['name' => 'name', 'type' => 'string', 'in' => 'path', 'required' => true, 'description' => 'Named Query name'],
            ['name' => 'params_json', 'type' => 'string', 'in' => 'body', 'required' => false, 'description' => 'Query parameters as a JSON object string matching the declared parameters of the query'],
        ],
    ],
    [
        'name' => 'named_query_list_all',
        'description' => 'List ALL Named Queries across every service (drafts and unpublished included). Returns id, service_id, name, description, is_active, published_revision_id and lock_version.',
        'http_method' => 'GET',
        'url' => $base . '/system/named_query',
        'parameters' => [],
    ],
    [
        'name' => 'named_query_get',
        'description' => 'Get the full definition of a Named Query by id, including every revision with its SQL and parameter declarations.',
        'http_method' => 'GET',
        'url' => $base . '/system/named_query/{id}',
        'parameters' => [
            ['name' => 'id', 'type' => 'integer', 'in' => 'path', 'required' => true, 'description' => 'Named Query id'],
        ],
    ],
    [
        'name' => 'named_query_create',
        'description' => 'Create a Named Query (starts as an inactive draft; publish it afterwards with named_query_publish). definition_json is a JSON object string: {"service_name":"py_ptg","name":"my_query","description":"...","sql":"SELECT ... WHERE x = :param","parameters":[{"name":"param","type":"string","required":true}]}. Parameter types: string|integer|number|boolean. SQL must start with SELECT or WITH, contain no semicolons and no DML keywords.',
        'http_method' => 'POST',
        'url' => $base . '/system/named_query',
        'parameters' => [
            ['name' => 'definition_json', 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => 'Named Query definition as a JSON object string'],
        ],
    ],
    [
        'name' => 'named_query_revise',
        'description' => 'Create a new revision of a Named Query (new draft SQL/parameters). definition_json is a JSON object string: {"lock_version":N,"service_id":X,"name":"...","sql":"...","parameters":[...]}. Get the current lock_version and service_id from named_query_get first. The published version does not change until named_query_publish runs on the new revision id.',
        'http_method' => 'PATCH',
        'url' => $base . '/system/named_query/{id}',
        'parameters' => [
            ['name' => 'id', 'type' => 'integer', 'in' => 'path', 'required' => true, 'description' => 'Named Query id'],
            ['name' => 'definition_json', 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => 'New revision definition as a JSON object string (must include current lock_version, service_id and name)'],
        ],
    ],
    [
        'name' => 'named_query_publish',
        'description' => 'Publish a revision of a Named Query, making it active/executable. Requires the current lock_version (from named_query_get) and the revision id to publish. On 409 Conflict the lock_version changed: re-read with named_query_get and retry.',
        'http_method' => 'PATCH',
        'url' => $base . '/system/named_query/{id}',
        'parameters' => [
            ['name' => 'id', 'type' => 'integer', 'in' => 'path', 'required' => true, 'description' => 'Named Query id'],
            ['name' => 'lock_version', 'type' => 'integer', 'in' => 'body', 'required' => true, 'description' => 'Current lock_version of the Named Query'],
            ['name' => 'publish_revision_id', 'type' => 'integer', 'in' => 'body', 'required' => true, 'description' => 'Revision id to publish'],
        ],
    ],
    [
        'name' => 'named_query_delete',
        'description' => 'Delete a Named Query permanently (all revisions go with it).',
        'http_method' => 'DELETE',
        'url' => $base . '/system/named_query/{id}',
        'parameters' => [
            ['name' => 'id', 'type' => 'integer', 'in' => 'path', 'required' => true, 'description' => 'Named Query id'],
        ],
    ],
];

foreach ($tools as $tool) {
    McpCustomTool::updateOrCreate(
        ['service_id' => $serviceId, 'name' => $tool['name']],
        [
            'tool_type' => 'api',
            'description' => $tool['description'],
            'http_method' => $tool['http_method'],
            'url' => $tool['url'],
            'parameters' => $tool['parameters'],
            'headers' => [],
            'enabled' => true,
        ]
    );
    echo "upserted: {$tool['name']}\n";
}

echo "total tools for service {$serviceId}: "
    . McpCustomTool::where('service_id', $serviceId)->count() . "\n";
