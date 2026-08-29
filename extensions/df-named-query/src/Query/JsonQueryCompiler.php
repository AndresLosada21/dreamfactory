<?php

namespace Yamaha\DreamFactory\NamedQuery\Query;

use DreamFactory\Core\Exceptions\BadRequestException;

/**
 * DSL JSON legado — RQ-032 / RQ-033.
 *
 * Formaliza o JSON Schema legado de api-query (QueryExecutorJDBCService.java:47-280,
 * QueryParamValueConverter.java:15-32, SqlExecutionLimits.java:50-87, QueryExecutionBudget.java:39-75)
 * com compilação para SQL preparado (sem interpolar valores) e import sem mudança semântica.
 *
 * @see api-query/config/query/acasala.json:1-33
 * @see api-query/config/query/wms-part-number.json:1-53
 * @see api-query/config/query/pymac-part-number.json:1-34
 * @see api-query/config/query/user-claim.json:26-47 (subQueries)
 */
class JsonQueryCompiler
{
    public const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '>', '>=', '<', '<=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL',
    ];

    public const ALLOWED_JOIN_TYPES = [
        'INNER', 'LEFT', 'RIGHT', 'FULL',
        'LEFT OUTER', 'RIGHT OUTER', 'FULL OUTER',
    ];

    public const ALLOWED_VALUE_TYPES = [
        'string', 'long', 'integer', 'double', 'number', 'decimal', 'boolean', 'date', 'datetime',
    ];

    public const DEFAULT_BUDGETS = [
        'max_rows' => 10000,
        'max_parameters' => 100,
        'max_parameter_value_length' => 4096,
        'max_in_items' => 100,
        'max_subquery_executions' => 500,
        'max_total_rows' => 10000,
        'max_total_bytes' => 10485760,
        // deadlines 45s — preserva SqlExecutionLimits.query-timeout-seconds:45 e request-timeout-seconds:45
        'query_timeout_seconds' => 45,
        'request_timeout_seconds' => 45,
        'timeout_seconds' => 45,
    ];

    /**
     * JSON Schema canónico legado (estrutura administrativa).
     * Equivalente a validar QueryDefinition.java:7-47 + MainQuery.java:6-31 + FilterGroup.java:6-31 + SubQuery.java:6-47
     */
    public const JSON_SCHEMA = [
        'type' => 'object',
        'required' => ['query'],
        'properties' => [
            'query' => [
                'type' => 'object',
                'required' => ['mainQuery'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'mainQuery' => [
                        'type' => 'object',
                        'required' => ['from', 'select'],
                        'properties' => [
                            'from' => ['type' => 'string', 'minLength' => 1],
                            'joins' => ['type' => 'array'],
                            'select' => ['type' => 'array', 'minItems' => 1],
                            'filters' => ['type' => 'array'],
                        ],
                    ],
                    'subQueries' => ['type' => 'array'],
                ],
            ],
        ],
    ];

    /**
     * Valida estrutura administrativa contra JSON Schema + allowlists.
     * Lança BadRequestException (400) em violação — espelha QueryExecutorJDBCService.validateRequiredFilters:208-254
     */
    public function validate(array $document): void
    {
        if (!isset($document['query']) || !is_array($document['query'])) {
            throw new BadRequestException('Legacy JSON: missing required "query" object.');
        }
        $root = $document['query'];
        if (!isset($root['mainQuery']) || !is_array($root['mainQuery'])) {
            throw new BadRequestException('Legacy JSON: missing required "query.mainQuery".');
        }
        $main = $root['mainQuery'];
        if (empty($main['from']) || !is_string($main['from'])) {
            throw new BadRequestException('Legacy JSON: "mainQuery.from" is required and must be a non-empty string.');
        }
        if (empty($main['select']) || !is_array($main['select'])) {
            throw new BadRequestException('Legacy JSON: "mainQuery.select" is required and must be a non-empty array.');
        }
        foreach ($main['select'] as $sel) {
            if (!is_string($sel) || trim($sel) === '') {
                throw new BadRequestException('Legacy JSON: each "mainQuery.select" entry must be a non-empty string.');
            }
        }
        // joins allowlist
        foreach ($main['joins'] ?? [] as $join) {
            $this->validateJoin($join);
        }
        foreach ($main['filters'] ?? [] as $group) {
            $this->validateFilterGroup($group);
        }
        foreach ($root['subQueries'] ?? [] as $sub) {
            $this->validateSubQuery($sub);
        }
    }

    private function validateJoin(mixed $join): void
    {
        if (!is_array($join)) {
            throw new BadRequestException('Legacy JSON: each join must be an object.');
        }
        foreach (['type', 'table', 'on'] as $field) {
            if (empty($join[$field]) || !is_string($join[$field])) {
                throw new BadRequestException("Legacy JSON: join.$field is required.");
            }
        }
        $type = strtoupper(trim($join['type']));
        // Normaliza "LEFT OUTER JOIN" etc — extrai primeiro token
        $canonical = preg_replace('/\s+JOIN$/i', '', $type);
        $canonical = trim($canonical);
        if (!in_array($canonical, self::ALLOWED_JOIN_TYPES, true) && !in_array($type, self::ALLOWED_JOIN_TYPES, true)) {
            // Permite INNER/LEFT/RIGHT/FULL como no QueryExecutorService.java:110-115
            $allowed = implode(', ', self::ALLOWED_JOIN_TYPES);
            throw new BadRequestException("Join type '$type' is not allowed. Allowed: $allowed.");
        }
        $this->assertNoTerminator($join['table'], 'join.table');
        $this->assertNoTerminator($join['on'], 'join.on');
    }

    private function validateFilterGroup(mixed $group): void
    {
        if (!is_array($group)) {
            throw new BadRequestException('Legacy JSON: each filter group must be an object.');
        }
        if (empty($group['groupId']) || !is_string($group['groupId'])) {
            throw new BadRequestException('Legacy JSON: filter group requires "groupId".');
        }
        $required = $group['required'] ?? [];
        $optional = $group['optional'] ?? [];
        if (!is_array($required) || !is_array($optional)) {
            throw new BadRequestException('Legacy JSON: filter group "required"/"optional" must be arrays.');
        }
        if (empty($group['conditions']) || !is_array($group['conditions'])) {
            throw new BadRequestException('Legacy JSON: filter group requires non-empty "conditions".');
        }
        foreach ($group['conditions'] as $cond) {
            $this->validateCondition($cond);
        }
    }

    private function validateCondition(mixed $cond): void
    {
        if (!is_array($cond)) {
            throw new BadRequestException('Legacy JSON: each condition must be an object.');
        }
        foreach (['column', 'op', 'param'] as $field) {
            if (empty($cond[$field]) || !is_string($cond[$field])) {
                throw new BadRequestException("Legacy JSON: condition.$field is required.");
            }
        }
        $op = strtoupper(trim($cond['op']));
        if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
            throw new BadRequestException("Operator '$op' is not allowed. Allowed: " . implode(', ', self::ALLOWED_OPERATORS));
        }
        if (isset($cond['valueType']) && $cond['valueType'] !== '' && $cond['valueType'] !== null) {
            $vt = strtolower(trim((string) $cond['valueType']));
            if (!in_array($vt, self::ALLOWED_VALUE_TYPES, true)) {
                throw new BadRequestException("valueType '$vt' is not allowed. Allowed: " . implode(', ', self::ALLOWED_VALUE_TYPES));
            }
        }
        $this->assertNoTerminator($cond['column'], 'condition.column');
    }

    private function validateSubQuery(mixed $sub): void
    {
        if (!is_array($sub)) {
            throw new BadRequestException('Legacy JSON: each subQuery must be an object.');
        }
        foreach (['name', 'bindKey', 'mainResultKey', 'from', 'select', 'mergeKey'] as $field) {
            if (empty($sub[$field])) {
                throw new BadRequestException("Legacy JSON: subQuery.$field is required.");
            }
        }
        if (!is_array($sub['select']) || empty($sub['select'])) {
            throw new BadRequestException('Legacy JSON: subQuery.select must be a non-empty array.');
        }
        foreach ($sub['joins'] ?? [] as $join) {
            $this->validateJoin($join);
        }
        foreach ($sub['filters'] ?? [] as $group) {
            $this->validateFilterGroup($group);
        }
    }

    private function assertNoTerminator(string $value, string $field): void
    {
        // Defesa contra multi-statement / terminador — espelha NamedSqlCompiler.assertReadOnly:62
        $stripped = $this->stripLiteralsAndComments($value);
        if (str_contains($stripped, ';')) {
            throw new BadRequestException("Field '$field' must not contain a statement terminator.");
        }
    }

    private function stripLiteralsAndComments(string $sql): string
    {
        return preg_replace('/\'[^\']*(?:\'\'[^\']*)*\'|"[^"]*(?:""[^"]*)*"|--[^\r\n]*|\/\*[\s\S]*?\*\//', ' ', $sql);
    }

    /**
     * Importa documento legado sem mudança semântica.
     * Normaliza defaults (required/optional como arrays vazios, joins como []) mantendo semântica.
     * Suporta tanto envelope {"query":{...}} quanto flat {"mainQuery":{...}}.
     */
    public function importLegacy(array $legacyDocument): array
    {
        // Aceita ambos formatos: com ou sem envelope "query"
        $query = $legacyDocument['query'] ?? $legacyDocument;
        if (!isset($query['mainQuery'])) {
            throw new BadRequestException('Legacy import: missing "mainQuery".');
        }
        $main = $query['mainQuery'];
        $normalized = [
            'query' => [
                'name' => $query['name'] ?? $legacyDocument['name'] ?? null,
                'mainQuery' => [
                    'from' => trim((string) ($main['from'] ?? '')),
                    'select' => array_values((array) ($main['select'] ?? [])),
                    'joins' => array_values((array) ($main['joins'] ?? [])),
                    'filters' => array_values((array) ($main['filters'] ?? [])),
                ],
            ],
        ];
        // Normaliza filtros: garante required/optional
        foreach ($normalized['query']['mainQuery']['filters'] as &$group) {
            $group['required'] = array_values((array) ($group['required'] ?? []));
            $group['optional'] = array_values((array) ($group['optional'] ?? []));
            $group['conditions'] = array_values((array) ($group['conditions'] ?? []));
            foreach ($group['conditions'] as &$cond) {
                $cond['valueType'] = $cond['valueType'] ?? null;
            }
            unset($cond);
        }
        unset($group);
        if (isset($query['subQueries']) && is_array($query['subQueries'])) {
            $normalized['query']['subQueries'] = array_values($query['subQueries']);
            foreach ($normalized['query']['subQueries'] as &$sub) {
                $sub['joins'] = array_values((array) ($sub['joins'] ?? []));
                $sub['filters'] = array_values((array) ($sub['filters'] ?? []));
                foreach ($sub['filters'] as &$g) {
                    $g['required'] = array_values((array) ($g['required'] ?? []));
                    $g['optional'] = array_values((array) ($g['optional'] ?? []));
                }
                unset($g);
            }
            unset($sub);
        }
        $this->validate($normalized);

        return $normalized;
    }

    /**
     * Compila DSL JSON legado para SQL preparado (sem interpolar valores).
     * Reproduz QueryExecutorJDBCService.buildSql:150-182 + buildWhereClause:185-206 + validateRequiredFilters:208-254
     *
     * @param array $document Documento normalizado (com envelope query.mainQuery)
     * @param array $params Mapa paramName => string raw (query string params)
     * @param array $budgets Budgets opcionais (max_rows, max_parameters, max_in_items, max_subquery_executions, query_timeout_seconds, request_timeout_seconds, max_total_bytes)
     * Cluster-safe: budgets vêm de DEFAULT_BUDGETS mesclado com revision.budgets lido direto do DB por request (sem cache local retido).
     */
    public function compile(array $document, array $params, array $budgets = []): CompiledSql
    {
        $this->validate($document);
        $budgets = array_merge(self::DEFAULT_BUDGETS, $budgets);
        // Deadline reduz timeout de statements quando supplied: statementTimeout = min(query_timeout, remaining)
        $this->validateRequestParameters($params, $budgets);

        $main = $document['query']['mainQuery'];
        $bindings = [];
        $index = 0;

        $sql = $this->buildSql(
            $main['from'],
            $main['joins'] ?? [],
            $main['select'],
            $main['filters'] ?? [],
            $params,
            $bindings,
            $index,
            $budgets
        );

        return new CompiledSql($sql, $bindings);
    }

    /**
     * Compila main + subQueries com budgets de subquery (RQ-033).
     * Valida que (resultSize * subQueryCount) não excede max_subquery_executions — SqlExecutionLimits.validateSubqueryExecutions:77-80
     *
     * @return array{main: CompiledSql, subQueries: array<string, CompiledSql>}
     */
    public function compileWithSubQueries(array $document, array $params, int $parentResultCount = 1, array $budgets = []): array
    {
        $mainCompiled = $this->compile($document, $params, $budgets);
        $budgets = array_merge(self::DEFAULT_BUDGETS, $budgets);
        $subQueries = $document['query']['subQueries'] ?? [];
        if (!empty($subQueries)) {
            $executions = $parentResultCount * count($subQueries);
            $this->validateSubqueryExecutions($executions, $budgets);
        }
        $subCompiled = [];
        foreach ($subQueries as $sub) {
            $bindings = [];
            $index = 0;
            // Para compilação estática de subQuery, injeta bindKey como placeholder de merge (valor simulado)
            // O bind real (parentRow[mainResultKey]) será ligado em runtime — aqui validamos estrutura e budgets IN.
            $simulatedParams = $params;
            // Garante que bindKey exista para não falhar required do grupo interno (se houver)
            if (!array_key_exists($sub['bindKey'], $simulatedParams)) {
                $simulatedParams[$sub['bindKey']] = '0';
            }
            $sql = $this->buildSql(
                $sub['from'],
                $sub['joins'] ?? [],
                $sub['select'],
                $sub['filters'] ?? [],
                $simulatedParams,
                $bindings,
                $index,
                $budgets
            );
            $subCompiled[$sub['name']] = new CompiledSql($sql, $bindings);
        }

        return ['main' => $mainCompiled, 'subQueries' => $subCompiled];
    }

    private function buildSql(string $from, array $joins, array $selectFields, array $filterGroups, array $params, array &$outBindings, int &$index, array $budgets): string
    {
        $this->validateRequiredFilters($filterGroups, $params);

        $sql = 'SELECT ' . implode(', ', $selectFields) . ' FROM ' . $from;
        foreach ($joins as $join) {
            $sql .= ' ' . strtoupper($join['type']) . ' JOIN ' . $join['table'] . ' ON ' . $join['on'];
        }

        $conditions = [];
        foreach ($filterGroups as $group) {
            if (!$this->isGroupApplicable($group, $params)) {
                continue;
            }
            foreach ($group['conditions'] ?? [] as $cond) {
                $paramValue = $params[$cond['param']] ?? null;
                if ($paramValue === null || (is_string($paramValue) && trim($paramValue) === '')) {
                    continue;
                }
                $conditions[] = $this->buildWhereClause($cond, (string) $paramValue, $outBindings, $index, $budgets);
            }
        }
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $this->validateBindCount(count($outBindings), $budgets);

        return $sql;
    }

    /**
     * Espelha QueryExecutorJDBCService.buildWhereClause:185-206
     * @throws BadRequestException em IN overflow ou valueType inválido
     */
    private function buildWhereClause(array $cond, string $value, array &$outBindings, int &$index, array $budgets): string
    {
        $this->validateValue($cond['param'], $value, $budgets);
        $column = $cond['column'];
        $op = strtoupper(trim($cond['op']));

        if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            return $column . ' ' . $op;
        }

        if ($op === 'IN' || $op === 'NOT IN') {
            $parts = explode(',', $value);
            $parts = array_map('trim', $parts);
            // Remove vazios causados por ",," mas preserva "0"
            $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
            if (empty($parts)) {
                throw new BadRequestException("Parameter '{$cond['param']}' IN requires at least one value.");
            }
            $this->validateInItemCount($cond['param'], count($parts), $budgets);
            $placeholders = [];
            foreach ($parts as $part) {
                $key = $cond['param'] . '_' . $index++;
                $outBindings[$key] = $this->coerceValue($cond['param'], $part, $cond['valueType'] ?? null);
                $placeholders[] = ':' . $key;
            }

            return $column . ' ' . $op . ' (' . implode(', ', $placeholders) . ')';
        }

        $key = $cond['param'] . '_' . $index++;
        $outBindings[$key] = $this->coerceValue($cond['param'], $value, $cond['valueType'] ?? null);

        return $column . ' ' . $op . ' :' . $key;
    }

    /**
     * Coerção de valores — espelha QueryParamValueConverter.convert:15-32
     * Preserva zeros quando valueType=string (RQ-033).
     */
    private function coerceValue(string $paramName, string $value, ?string $configuredType): mixed
    {
        if ($configuredType === null || trim($configuredType) === '') {
            return $this->infer($value);
        }
        $normalizedType = strtolower(trim($configuredType));

        return match ($normalizedType) {
            'string' => $value, // preserva "00123" — RQ-033
            'long', 'integer' => $this->parseLong($value, $paramName, $normalizedType),
            'double', 'number', 'decimal' => $this->parseDouble($value, $paramName, $normalizedType),
            'boolean' => $this->parseBool($value, $paramName, $normalizedType),
            'date' => $this->parseDate($value, $paramName, $normalizedType),
            'datetime' => $this->parseDateTime($value, $paramName, $normalizedType),
            default => throw new BadRequestException("Unsupported parameter type: $configuredType for $paramName"),
        };
    }

    private function infer(string $value): mixed
    {
        // Espelha QueryParamValueConverter.infer:34-41 — tenta Long, Double, Boolean, LocalDate, LocalDateTime
        if (preg_match('/^-?\d+$/', $value)) {
            // Preserva se tem zeros à esquerda e value parece string pid? Infer converte "00123" -> 123 (perde zeros)
            // Mantém fidelidade com legado Java.
            try {
                return (int) $value;
            } catch (\Throwable $ignored) {}
        }
        if (is_numeric($value)) {
            // Double parse
            $float = (float) $value;
            if ((string) $float !== '' ) {
                // Se é numerico puro sem alfa, retorna float/int accordingly — mas preserva infer legado
                if (str_contains($value, '.') || stripos($value, 'e') !== false) {
                    return $float;
                }
            }
        }
        $lower = strtolower($value);
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }
        // Date/DateTime ISO — tenta parse Y-m-d e Y-m-d H:i:s
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($d && $d->format('Y-m-d') === $value) {
            return $d;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if ($dt && $dt->format('Y-m-d H:i:s') === $value) {
            return $dt;
        }
        $dt2 = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);
        if ($dt2) {
            return $dt2;
        }

        return $value;
    }

    private function parseLong(string $value, string $paramName, string $expectedType): int
    {
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new BadRequestException("Invalid value for parameter '$paramName'. Expected: $expectedType");
        }

        return (int) $value;
    }

    private function parseDouble(string $value, string $paramName, string $expectedType): float
    {
        if (!is_numeric($value)) {
            throw new BadRequestException("Invalid value for parameter '$paramName'. Expected: $expectedType");
        }

        return (float) $value;
    }

    private function parseBool(string $value, string $paramName, string $expectedType): bool
    {
        $lower = strtolower($value);
        if ($lower !== 'true' && $lower !== 'false') {
            throw new BadRequestException("Invalid value for parameter '$paramName'. Expected: $expectedType");
        }

        return $lower === 'true';
    }

    private function parseDate(string $value, string $paramName, string $expectedType): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new BadRequestException("Invalid value for parameter '$paramName'. Expected: $expectedType");
        }

        return $d;
    }

    private function parseDateTime(string $value, string $paramName, string $expectedType): \DateTimeImmutable
    {
        // Aceita Y-m-d H:i:s ou ISO8601
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s.u', \DateTimeInterface::ATOM] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $value);
            if ($d) {
                return $d;
            }
        }
        // Fallback strtotime
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            throw new BadRequestException("Invalid value for parameter '$paramName'. Expected: $expectedType");
        }
    }

    /**
     * Espelha QueryExecutorJDBCService.validateRequiredFilters:208-254 + SqlExecutionLimits
     */
    private function validateRequiredFilters(array $filterGroups, array $params): void
    {
        if (empty($filterGroups)) {
            return;
        }
        $requiredGroups = [];
        $missingFromPartialGroup = [];
        $anyApplicableRequiredGroup = false;
        $anyKnownParamProvided = false;

        foreach ($filterGroups as $group) {
            $groupParams = $this->groupParams($group);
            $groupReferenced = false;
            foreach ($groupParams as $p) {
                if ($this->hasParam($params, $p)) {
                    $groupReferenced = true;
                    break;
                }
            }
            if ($groupReferenced) {
                $anyKnownParamProvided = true;
            }
            $required = $group['required'] ?? [];
            if (empty($required)) {
                continue;
            }
            $requiredGroups[] = $required;
            $missing = [];
            foreach ($required as $param) {
                if (!$this->hasParam($params, $param)) {
                    $missing[] = $param;
                }
            }
            if (empty($missing)) {
                $anyApplicableRequiredGroup = true;
                continue;
            }
            if ($groupReferenced) {
                $missingFromPartialGroup = array_merge($missingFromPartialGroup, $missing);
            }
        }

        if (!empty($missingFromPartialGroup)) {
            $missingFromPartialGroup = array_unique($missingFromPartialGroup);
            throw new BadRequestException('Parametro(s) obrigatorio(s) ausente(s): ' . implode(', ', $missingFromPartialGroup));
        }

        if (!empty($requiredGroups) && !$anyApplicableRequiredGroup) {
            if (count($requiredGroups) === 1) {
                throw new BadRequestException('Parametro(s) obrigatorio(s): ' . implode(', ', $requiredGroups[0]));
            }
            $groupDescriptions = array_map(fn ($g) => '[' . implode(', ', $g) . ']', $requiredGroups);
            if ($anyKnownParamProvided) {
                throw new BadRequestException('Parametro(s) obrigatorio(s) ausente(s): informe ao menos um grupo valido: ' . implode(' ou ', $groupDescriptions));
            }
            throw new BadRequestException('Informe ao menos um grupo de parametros obrigatorios: ' . implode(' ou ', $groupDescriptions));
        }
    }

    private function isGroupApplicable(array $group, array $params): bool
    {
        $required = $group['required'] ?? [];
        if (empty($required)) {
            return true;
        }
        foreach ($required as $param) {
            if (!$this->hasParam($params, $param)) {
                return false;
            }
        }

        return true;
    }

    private function hasParam(array $params, string $paramName): bool
    {
        return isset($params[$paramName]) && trim((string) $params[$paramName]) !== '';
    }

    private function groupParams(array $group): array
    {
        $out = [];
        foreach ((array) ($group['required'] ?? []) as $p) { $out[] = $p; }
        foreach ((array) ($group['optional'] ?? []) as $p) { $out[] = $p; }
        foreach ((array) ($group['conditions'] ?? []) as $c) {
            if (!empty($c['param'])) { $out[] = $c['param']; }
        }

        return array_unique($out);
    }

    // ---- Budget validators (SqlExecutionLimits.java) ----

    private function validateRequestParameters(array $params, array $budgets): void
    {
        if (count($params) > (int) $budgets['max_parameters']) {
            throw new BadRequestException('Quantidade maxima de parametros excedida: ' . $budgets['max_parameters']);
        }
        foreach ($params as $name => $value) {
            $this->validateValue((string) $name, (string) $value, $budgets);
        }
    }

    private function validateValue(string $parameterName, string $value, array $budgets): void
    {
        if (mb_strlen($value) > (int) $budgets['max_parameter_value_length']) {
            throw new BadRequestException("Tamanho maximo excedido para o parametro '$parameterName': " . $budgets['max_parameter_value_length']);
        }
    }

    private function validateBindCount(int $bindCount, array $budgets): void
    {
        if ($bindCount > (int) $budgets['max_parameters']) {
            throw new BadRequestException('Quantidade maxima de parametros SQL excedida: ' . $budgets['max_parameters']);
        }
    }

    private function validateInItemCount(string $parameterName, int $itemCount, array $budgets): void
    {
        if ($itemCount > (int) $budgets['max_in_items']) {
            throw new BadRequestException("Quantidade maxima de itens excedida para o parametro '$parameterName': " . $budgets['max_in_items']);
        }
    }

    private function validateSubqueryExecutions(int $executionCount, array $budgets): void
    {
        if ($executionCount > (int) $budgets['max_subquery_executions']) {
            throw new BadRequestException('Quantidade maxima de subqueries excedida: ' . $budgets['max_subquery_executions']);
        }
    }
}
