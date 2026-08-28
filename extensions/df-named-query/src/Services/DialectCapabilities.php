<?php

namespace Yamaha\DreamFactory\NamedQuery\Services;

use DreamFactory\Core\Models\Service;

/**
 * RQ-021 — Abstração de capacidades por dialeto (driver-independent contract).
 *
 * Expõe para cada driver (pgsql, oracle, sqlsrv, informix) se suporta:
 * named binds, quoting, metadata, timeout, cancel, paginação, normalização, JSON, LOB.
 * - Capabilities consultáveis pela UI/engine (GET /api/v2/{service}/_query/capabilities).
 * - Publish bloqueado quando feature exigida não é suportada pelo driver da service_id.
 * - Contrato independente do driver (generalização, não leak de PDO/OCI).
 *
 * file:line anchors:
 *  - src/Services/DialectCapabilities.php:1  (contrato)
 *  - src/Query/DialectCapabilities.php:1     (alias re-export)
 *  - src/Repositories/NamedQueryRepository.php:publish (gate)
 *  - src/Resources/NamedQueryResource.php:handleGET (endpoint)
 *  - src/ServiceProvider.php:register (singleton)
 */
class DialectCapabilities
{
    public const FEATURE_NAMED_BINDS = 'named_binds';
    public const FEATURE_QUOTING = 'quoting';
    public const FEATURE_METADATA = 'metadata';
    public const FEATURE_TIMEOUT = 'timeout';
    public const FEATURE_CANCEL = 'cancel';
    public const FEATURE_PAGINATION = 'pagination';
    public const FEATURE_JSON = 'json';
    public const FEATURE_LOB = 'lob';
    public const FEATURE_NORMALIZATION = 'normalization';

    /**
     * @var array<string, array<string, mixed>>
     */
    private const MATRIX = [
        // PostgreSQL (pgsql_query) — referência canônica
        'pgsql' => [
            self::FEATURE_NAMED_BINDS => true,
            self::FEATURE_QUOTING => 'double', // "identifier"
            self::FEATURE_METADATA => true,    // information_schema / pg_catalog
            self::FEATURE_TIMEOUT => true,     // statement_timeout
            self::FEATURE_CANCEL => true,      // pg_cancel_backend
            self::FEATURE_PAGINATION => true,  // LIMIT/OFFSET
            self::FEATURE_JSON => true,        // json/jsonb + JSON_QUERY
            self::FEATURE_LOB => true,         // text/bytea
            self::FEATURE_NORMALIZATION => true,
        ],
        // Oracle (OCI8 / yajra/laravel-oci8)
        'oracle' => [
            self::FEATURE_NAMED_BINDS => true, // :name (OCI)
            self::FEATURE_QUOTING => 'double',
            self::FEATURE_METADATA => true,    // ALL_/USER_TABLES
            self::FEATURE_TIMEOUT => true,     // OCI query timeout / resource manager
            self::FEATURE_CANCEL => true,      // OCIBreak
            self::FEATURE_PAGINATION => true,  // OFFSET/FETCH | ROWNUM
            self::FEATURE_JSON => true,        // JSON type (21c) + JSON_QUERY
            self::FEATURE_LOB => true,         // CLOB/BLOB
            self::FEATURE_NORMALIZATION => true,
        ],
        // SQL Server (pdo_sqlsrv / msodbcsql)
        'sqlsrv' => [
            self::FEATURE_NAMED_BINDS => true,
            self::FEATURE_QUOTING => 'bracket', // [identifier]
            self::FEATURE_METADATA => true,     // sys.*
            self::FEATURE_TIMEOUT => true,      // PDO ATTR_TIMEOUT / queryTimeout
            self::FEATURE_CANCEL => true,
            self::FEATURE_PAGINATION => true,   // OFFSET/FETCH
            self::FEATURE_JSON => true,         // NVARCHAR + ISJSON / JSON_QUERY / FOR JSON
            self::FEATURE_LOB => true,          // varchar(max)/varbinary(max)/text
            self::FEATURE_NORMALIZATION => true,
        ],
        // Informix (PDO_INFORMIX / CSDK)
        'informix' => [
            self::FEATURE_NAMED_BINDS => false, // positional ? only — PDO_INFORMIX limitation
            self::FEATURE_QUOTING => 'double',
            self::FEATURE_METADATA => true,     // systables/syscolumns
            self::FEATURE_TIMEOUT => false,     // no statement_timeout
            self::FEATURE_CANCEL => false,      // no cancel hook
            self::FEATURE_PAGINATION => true,   // SKIP/FIRST (not LIMIT/OFFSET)
            self::FEATURE_JSON => false,        // LVARCHAR JSON as text, no native engine
            self::FEATURE_LOB => true,          // TEXT/BYTE/CLOB/BLOB
            self::FEATURE_NORMALIZATION => true,
        ],
    ];

    private const SERVICE_TYPE_MAP = [
        'pgsql_query' => 'pgsql',
        'pgsql' => 'pgsql',
        'oracle' => 'oracle',
        'sqlsrv' => 'sqlsrv',
        'informix' => 'informix',
    ];

    public static function all(): array
    {
        return self::MATRIX;
    }

    public static function drivers(): array
    {
        return array_keys(self::MATRIX);
    }

    public static function forDriver(string $driver): array
    {
        $driver = strtolower($driver);
        if (!isset(self::MATRIX[$driver])) {
            throw new \InvalidArgumentException("Unknown dialect driver '$driver'.");
        }

        return self::MATRIX[$driver];
    }

    public static function forServiceType(string $serviceType): array
    {
        $driver = self::serviceTypeToDriver($serviceType);

        return self::forDriver($driver);
    }

    public static function forService(Service $service): array
    {
        return self::forServiceType((string) $service->type);
    }

    public static function forServiceId(int $serviceId): array
    {
        $service = Service::find($serviceId);
        if (!$service) {
            throw new \InvalidArgumentException("Service '$serviceId' not found.");
        }

        return self::forService($service);
    }

    public static function serviceTypeToDriver(string $serviceType): string
    {
        $key = strtolower($serviceType);

        return self::SERVICE_TYPE_MAP[$key] ?? $key;
    }

    public static function supports(string $driver, string $feature): bool
    {
        $caps = self::forDriver($driver);

        return (bool) ($caps[$feature] ?? false);
    }

    public static function supportsForServiceType(string $serviceType, string $feature): bool
    {
        return self::supports(self::serviceTypeToDriver($serviceType), $feature);
    }

    /**
     * Detecta capabilities exigidas por uma definição (sql/parameters/output_schema/budgets).
     * Contrato independente do driver: inspeção estática do payload versionado.
     *
     * @return string[] feature keys exigidas
     */
    public static function requiredForDefinition(array $definition): array
    {
        $required = [];
        $sql = (string) ($definition['sql'] ?? '');
        $outputSchema = $definition['output_schema'] ?? [];
        $budgets = $definition['budgets'] ?? [];
        $parameters = $definition['parameters'] ?? [];

        // Pagination: max_rows budget OR SQL contiene cláusulas de paginação
        if (array_key_exists('max_rows', is_array($budgets) ? $budgets : [])) {
            $required[] = self::FEATURE_PAGINATION;
        }
        if (preg_match('/\b(LIMIT|OFFSET|FETCH\s+NEXT|SKIP|FIRST)\b/i', $sql)) {
            $required[] = self::FEATURE_PAGINATION;
        }

        // JSON: FOR JSON | JSON_QUERY/VALUE/OBJECT | ::json | output_schema type json
        if (preg_match('/\b(FOR\s+JSON|JSON_QUERY|JSON_VALUE|JSON_OBJECT|IS\s+JSON)\b/i', $sql)
            || str_contains(strtolower($sql), '::json')) {
            $required[] = self::FEATURE_JSON;
        }
        if (is_array($outputSchema)) {
            foreach ($outputSchema as $col) {
                if (is_array($col) && strtolower((string) ($col['type'] ?? '')) === 'json') {
                    $required[] = self::FEATURE_JSON;
                    break;
                }
            }
        }

        // LOB: CLOB/BLOB/TEXT/BYTE/LVARCHAR em SQL ou output_schema
        if (preg_match('/\b(CLOB|BLOB|TEXT|BYTE|LVARCHAR)\b/i', $sql)) {
            $required[] = self::FEATURE_LOB;
        }
        if (is_array($outputSchema)) {
            foreach ($outputSchema as $col) {
                $t = strtolower((string) (is_array($col) ? ($col['type'] ?? '') : ''));
                if (in_array($t, ['lob', 'binary', 'text'], true)) {
                    $required[] = self::FEATURE_LOB;
                    break;
                }
            }
        }

        // Named binds: :name occurrences (beyond pgsql casts ::)
        if (preg_match('/(?<!:):[A-Za-z_][A-Za-z0-9_]*/', $sql)) {
            // positional fallback still counts; but gate uses named_binds
            // If driver não suporta named_binds, publish deve bloquear quando há parâmetro nomeado
            if (!empty($parameters)) {
                $required[] = self::FEATURE_NAMED_BINDS;
            }
        }

        // Metadata: se SQL referencia catálogos (information_schema, sys., systables) — implicit
        // Não bloqueia; apenas registra que metadata é usado.

        // Timeout/cancel/normalization são transversais — não inferidos do SQL.

        return array_values(array_unique($required));
    }

    /**
     * Valida se o driver suporta tudo que a definição exige; lança BadRequest se não.
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     */
    public static function assertSupported(string $driver, array $definition): void
    {
        $required = self::requiredForDefinition($definition);
        $caps = self::forDriver($driver);
        $unsupported = [];
        foreach ($required as $feature) {
            if (empty($caps[$feature])) {
                $unsupported[] = $feature;
            }
        }
        if ($unsupported !== []) {
            throw new \DreamFactory\Core\Exceptions\BadRequestException(
                'Named Query requires unsupported capability on driver \'' . $driver . '\': ' . implode(', ', $unsupported) . '.'
            );
        }
    }

    public static function assertSupportedForServiceType(string $serviceType, array $definition): void
    {
        self::assertSupported(self::serviceTypeToDriver($serviceType), $definition);
    }

    /**
     * Payload consultável pela UI/engine (independente do driver).
     */
    public static function payloadForDriver(string $driver): array
    {
        return [
            'driver' => $driver,
            'capabilities' => self::forDriver($driver),
            'features' => array_keys(array_filter(self::forDriver($driver))),
        ];
    }

    public static function payloadForServiceType(string $serviceType): array
    {
        $driver = self::serviceTypeToDriver($serviceType);

        return [
            'service_type' => $serviceType,
            'driver' => $driver,
            'capabilities' => self::forDriver($driver),
            'features' => array_keys(array_filter(self::forDriver($driver))),
        ];
    }
}
