<?php

namespace Yamaha\DreamFactory\NamedQuery\Query;

use DreamFactory\Core\Exceptions\BadRequestException;
use Yamaha\DreamFactory\NamedQuery\Services\DialectCapabilities;

class NamedSqlCompiler
{
    /**
     * RQ-021 — capacidades consultáveis pela UI/engine.
     * Expõe bind/quoting/metadata/timeout/cancel/paginacao/normalizacao/JSON/LOB por conector.
     * Contrato independente do driver — não leaka PDO/OCI.
     */
    public static function capabilities(string $driver): array
    {
        return DialectCapabilities::forDriver($driver);
    }

    public static function supports(string $driver, string $feature): bool
    {
        return DialectCapabilities::supports($driver, $feature);
    }
    public function compile(string $sql, array $declarations, array $values): CompiledSql
    {
        $this->assertReadOnly($sql);

        $declared = [];
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || empty($declaration['name'])) {
                throw new BadRequestException('Every parameter declaration requires a name.');
            }
            $declared[$declaration['name']] = $declaration;
        }

        $bindings = [];
        $index = 0;
        $compiled = $this->replaceParameters($sql, function (string $name) use (&$bindings, &$index, $declared, $values) {
            if (!array_key_exists($name, $declared)) {
                throw new BadRequestException("Parameter '$name' is not declared for this query.");
            }
            if (!array_key_exists($name, $values)) {
                if (!empty($declared[$name]['required'])) {
                    throw new BadRequestException("Required parameter '$name' is missing.");
                }
                $value = $declared[$name]['default'] ?? null;
            } else {
                $value = $values[$name];
            }

            $key = $name . '_' . $index++;
            $bindings[$key] = $this->coerceValue($name, $value, $declared[$name]);

            return ':' . $key;
        });

        return new CompiledSql($compiled, $bindings);
    }

    public function assertReadOnly(string $sql, bool $allowMutation = false): void
    {
        // RQ-031: mutação futura só com flag explícita; sem flag, falha no publish.
        // Se allowMutation true mas flag não ativa, mantém read-only (falha).
        if ($allowMutation) {
            if ($this->isExplicitMutationAllowed()) {
                return;
            }
            // fall through to read-only validation which will reject DML
        }

        $tokens = $this->tokens($sql);
        if (empty($tokens) || !in_array($tokens[0], ['SELECT', 'WITH'], true)) {
            throw new BadRequestException('Named SQL must start with SELECT or WITH.');
        }

        $forbidden = [
            'ALTER', 'ANALYZE', 'ATTACH', 'CALL', 'CHECKPOINT', 'CLUSTER', 'COMMENT', 'COPY', 'CREATE',
            'DECLARE', 'DELETE', 'DETACH', 'DROP', 'EXEC', 'EXECUTE', 'EXPORT', 'GRANT', 'HANDLER',
            'IMPORT', 'INSERT', 'LOAD', 'LOCK', 'MERGE', 'PRAGMA', 'REINDEX', 'REPLACE', 'REVOKE',
            'TRUNCATE', 'UNLOCK', 'UPDATE', 'UPSERT', 'VACUUM',
        ];
        foreach ($tokens as $token) {
            if (in_array($token, $forbidden, true)) {
                throw new BadRequestException("Keyword '$token' is not permitted in read-only Named SQL.");
            }
        }
        // Defense-in-depth: SET/SHOW are also blocked as statements, but also catch via forbidden above;
        // keep explicit patterns for locking clauses that contain spaces.
        $normalized = $this->stripLiteralsAndComments($sql);
        if (str_contains($normalized, ';')) {
            throw new BadRequestException('Named SQL must not contain a statement terminator.');
        }
        if (preg_match('/\bFOR\s+(UPDATE|SHARE)\b/i', $normalized)) {
            throw new BadRequestException('FOR UPDATE/SHARE is not permitted in read-only Named SQL.');
        }
        if (preg_match('/\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $normalized)) {
            throw new BadRequestException('LOCK IN SHARE MODE is not permitted in read-only Named SQL.');
        }
        if (preg_match('/\bSELECT\b[\s\S]*\bINTO\b/i', $normalized)) {
            throw new BadRequestException('SELECT INTO is not permitted in read-only Named SQL.');
        }
        // UNION is allowed (e.g., pymac-origin-destination uses UNION), but corpus ensures UNION injection
        // via terminators/comments is already blocked by ; and forbidden DML checks above.
    }

    private function isExplicitMutationAllowed(): bool
    {
        // Future mutation mode must be explicitly enabled via env/config flag.
        // No mutation is allowed by default; publish will fail without this flag.
        $env = getenv('NAMED_QUERY_ALLOW_MUTATION');
        if ($env !== false && $env !== '' && $env !== '0' && strtolower((string) $env) !== 'false') {
            return true;
        }
        if (function_exists('config')) {
            try {
                if (config('named-query.allow_mutation') === true) {
                    return true;
                }
            } catch (\Throwable $e) {
                // config not available in minimal test bootstrap
            }
        }

        return false;
    }

    private function coerceValue(string $name, mixed $value, array $declaration): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($declaration['type'] ?? 'string') {
            'integer' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
                ?? throw new BadRequestException("Parameter '$name' must be an integer."),
            'number' => is_numeric($value) ? $value + 0 : throw new BadRequestException("Parameter '$name' must be numeric."),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new BadRequestException("Parameter '$name' must be boolean."),
            default => is_scalar($value) ? (string) $value : throw new BadRequestException("Parameter '$name' must be scalar."),
        };
    }

    private function replaceParameters(string $sql, callable $replacement): string
    {
        return preg_replace_callback(
            '/(?:\'[^\']*(?:\'\'[^\']*)*\'|"[^"]*(?:""[^"]*)*"|--[^\r\n]*|\/\*[\s\S]*?\*\/)(*SKIP)(*F)|(?<!:):([A-Za-z_][A-Za-z0-9_]*)/',
            fn (array $match) => $replacement($match[1]),
            $sql
        );
    }

    private function tokens(string $sql): array
    {
        return preg_split('/[^A-Z_]+/', strtoupper($this->stripLiteralsAndComments($sql)), -1, PREG_SPLIT_NO_EMPTY);
    }

    private function stripLiteralsAndComments(string $sql): string
    {
        return preg_replace('/\'[^\']*(?:\'\'[^\']*)*\'|"[^"]*(?:""[^"]*)*"|--[^\r\n]*|\/\*[\s\S]*?\*\//', ' ', $sql);
    }
}
