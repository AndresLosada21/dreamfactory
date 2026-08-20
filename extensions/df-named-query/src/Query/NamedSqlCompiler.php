<?php

namespace Yamaha\DreamFactory\NamedQuery\Query;

use DreamFactory\Core\Exceptions\BadRequestException;

class NamedSqlCompiler
{
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

    public function assertReadOnly(string $sql): void
    {
        $tokens = $this->tokens($sql);
        if (empty($tokens) || !in_array($tokens[0], ['SELECT', 'WITH'], true)) {
            throw new BadRequestException('Named SQL must start with SELECT or WITH.');
        }

        $forbidden = [
            'ALTER', 'CALL', 'CREATE', 'DELETE', 'DROP', 'EXEC', 'EXECUTE', 'GRANT',
            'INSERT', 'MERGE', 'REPLACE', 'REVOKE', 'TRUNCATE', 'UPDATE', 'UPSERT',
        ];
        foreach ($tokens as $token) {
            if (in_array($token, $forbidden, true)) {
                throw new BadRequestException("Keyword '$token' is not permitted in read-only Named SQL.");
            }
        }
        $normalized = $this->stripLiteralsAndComments($sql);
        if (str_contains($normalized, ';')) {
            throw new BadRequestException('Named SQL must not contain a statement terminator.');
        }
        if (preg_match('/\bFOR\s+UPDATE\b/i', $normalized)) {
            throw new BadRequestException('FOR UPDATE is not permitted in read-only Named SQL.');
        }
        if (preg_match('/\bSELECT\b[\s\S]*\bINTO\b/i', $normalized)) {
            throw new BadRequestException('SELECT INTO is not permitted in read-only Named SQL.');
        }
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
