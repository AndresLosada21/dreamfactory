<?php

namespace Yamaha\DreamFactory\NamedQuery\Query;

/**
 * RQ-034 — Normalizador determinístico por executor e banco.
 *
 * Espelha ResultSetUtil.java:11-252 + QueryExecutorJDBCService.java:117-133
 * Garante:
 *  - lowercase labels (ResultSetMetaData.getColumnLabel lowercased — ResultSetUtil.java:64)
 *  - aliases pontilhados preservados (inspecao.pecas, motor.numero — types-nulls-labels.json:8-14)
 *  - datas/timestamps/decimais/bytes/CLOB/JSON normalizados por banco
 *  - null/unicode/binary/precisão reproduzidos com golden determinístico por executor/banco
 *
 * @see api-query/tests/blackbox/fixtures/goldens/types-nulls-labels.json:1-68
 * @see api-query/tests/blackbox/fixtures/goldens/success-envelope.json:32-38
 */
class ResultNormalizer
{
    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.v';
    public const DATE_FORMAT = 'Y-m-d';
    public const TIME_FORMAT = 'H:i:s';

    /**
     * Normaliza um conjunto completo de linhas.
     * Determinístico por executor/dbType — mesma entrada produz mesma saída.
     *
     * @param array $rows Lista de mapas associativos (cada row = col=>value)
     * @param string $executor 'jdbc'|'jooq'|'pdo'|'pgsql' etc — usado para golden divergência controlada
     * @param string $dbType 'oracle'|'sqlsrv'|'pgsql'|'informix'|'postgres'
     * @return array Linhas normalizadas
     */
    public function normalizeRows(array $rows, string $executor = 'pdo', string $dbType = 'pgsql'): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = $this->normalizeRow((array) $row, $executor, $dbType);
        }

        return $normalized;
    }

    /**
     * Normaliza uma linha: lowercases labels, preserva pontilhados, normaliza tipos.
     *
     * @see ResultSetUtil.java:64 row.put(columnName.toLowerCase(), value)
     * @see QueryExecutorJDBCService.java:129-130 alias após último espaço
     */
    public function normalizeRow(array $row, string $executor = 'pdo', string $dbType = 'pgsql'): array
    {
        $out = [];
        foreach ($row as $label => $value) {
            $normalizedLabel = $this->normalizeLabel((string) $label);
            $out[$normalizedLabel] = $this->normalizeValue($value, $executor, $dbType);
        }

        return $out;
    }

    /**
     * Lowercases labels e preserva aliases pontilhados.
     * - Extrai alias após último espaço (QueryExecutorJDBCService.java:129-130)
     * - Remove brackets [] e aspas "" remanescentes (gq-inspecao AS [inspecao.pecas])
     * - Lowercases via mb_strtolower (ResultSetUtil.java:64)
     * - Preserva dots: 'motor.numero', 'inspecao.pecas' (types-nulls-labels.json:13)
     */
    public function normalizeLabel(string $label): string
    {
        $label = trim($label);
        // Alias após último espaço — "RTRIM(pc.CD_CHASSI) AS chassi" -> "chassi" ou "substr(...) nr_carcaca" -> "nr_carcaca"
        if (str_contains($label, ' ')) {
            $label = substr($label, strrpos($label, ' ') + 1);
        }
        // Remove brackets SQL Server: [inspecao.pecas] -> inspecao.pecas
        $label = trim($label, '[]"\'`');
        // Lowercase determinístico — nunca uppercase (types-nulls-labels.json:12)
        $label = mb_strtolower($label, 'UTF-8');

        return $label;
    }

    /**
     * Normaliza valor por tipo SQL / driver / executor.
     *
     * - null permanece null (ResultSet.wasNull() -> null — ResultSetUtil.java:87,94,98)
     * - Datas: DATE -> yyyy-MM-dd, TIMESTAMP -> yyyy-MM-dd HH:mm:ss.SSS (ResultSetUtil.java:133-146)
     * - Decimais: preserva precisão de BigDecimal (NUMERIC/DECIMAL — ResultSetUtil.java:119-123)
     * - Bytes/BLOB/CLOB: binary -> base64 se não-UTF8 ou contém null byte; CLOB -> string + parseIfJson
     * - JSON embutido: parseIfJson (ResultSetUtil.java:229-251) — "{"/"[" tenta decode
     * - Unicode: preservado intacto (mb_check_encoding UTF-8)
     * - BIT/boolean: distingue false vs null
     *
     * @param mixed $value
     * @param string $executor
     * @param string $dbType
     */
    public function normalizeValue(mixed $value, string $executor = 'pdo', string $dbType = 'pgsql'): mixed
    {
        if ($value === null) {
            return null;
        }

        // Recursos PDO que podem vir como stream/resource (CLOB/BLOB em Oracle/Informix)
        if (is_resource($value)) {
            $value = stream_get_contents($value);
            if ($value === false) {
                return null;
            }
        }

        // DateTimeInterface (driver já converteu)
        if ($value instanceof \DateTimeInterface) {
            return $this->formatDateTime($value, $dbType);
        }

        // Array já decodificado (JSON ou ARRAY sql type — ResultSetUtil.java:188-205)
        if (is_array($value)) {
            // Normaliza recursivamente itens para unicode/binary determinístico
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeValue($v, $executor, $dbType);
            }

            return $out;
        }

        // Binary / bytes — detecta binário não-UTF8 (BLOB/BINARY — ResultSetUtil.java:150-164)
        if (is_string($value) && $this->isBinary($value)) {
            // Golden: byte[] -> base64 bytes path (types-nulls-labels.json:31 rare)
            // Mantém determinístico por banco: mesmo bytes -> mesmo base64
            return base64_encode($value);
        }

        // Numeric handling por executor/banco — preserva precisão (ResultSetUtil.java:119-123 NUMERIC/DECIMAL BigDecimal)
        if (is_int($value) || is_float($value)) {
            // Decimais: normaliza representação para evitar divergência entre pgsql double vs oracle number
            if (is_float($value)) {
                return $this->normalizeDecimal($value, $dbType);
            }

            return $value;
        }

        // Boolean / BIT (ResultSetUtil.java:126-130) — distingue false vs null já tratado; cast explícito
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            // CLOB/NCLOB já como string — aplica parseIfJson (ResultSetUtil.java:167-184)
            // Datas retornadas como string por alguns drivers (sqlsrv/oracle) — normaliza formato
            $trimmed = trim($value);

            // Tenta normalizar datas em string vindas de drivers sem tipagem (sqlsrv, oracle)
            $dateNormalized = $this->tryNormalizeDateString($trimmed, $dbType);
            if ($dateNormalized !== null) {
                return $dateNormalized;
            }

            // JSON embutido: inicia com { ou [ -> tenta decode (ResultSetUtil.java:229-251 + gq-inspecao JSON_QUERY)
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Normaliza conteúdo decodificado recursivamente (unicode/binary)
                    if (is_array($decoded)) {
                        foreach ($decoded as $k => $v) {
                            $decoded[$k] = $this->normalizeValue($v, $executor, $dbType);
                        }
                    }

                    return $decoded;
                }
            }

            // String comum — unicode preservado (não double-encode)
            // Numeric strings de alta precisão: preserva como string se NUMERIC/DECIMAL de banco
            if ($this->isHighPrecisionDecimalString($trimmed)) {
                // Golden: NUMERIC/DECIMAL serializado como number mas preserva precisão — mantém string se perderia precisão em float
                return $trimmed;
            }

            return $value;
        }

        // Fallback: objetos genéricos (BigDecimal de alguns drivers vem como string/numeric)
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                $str = (string) $value;
                // Reaplica normalização de string
                return $this->normalizeValue($str, $executor, $dbType);
            }
        }

        return $value;
    }

    /**
     * Formata DateTime conforme ResultSetUtil.TIMESTAMP_FORMATTER
     * @see ResultSetUtil.java:15-16 "yyyy-MM-dd HH:mm:ss.SSS"
     */
    private function formatDateTime(\DateTimeInterface $value, string $dbType): string
    {
        // Distingue DATE puro (hora = 00:00:00 e sem micros) vs TIMESTAMP
        // Usa micros: se 0 e hora zero, trata como DATE? Mantém heuristic conservadora: sempre TIMESTAMP_FORMAT se houver tempo
        $hasTime = $value->format('H:i:s') !== '00:00:00' || $value->format('u') !== '000000';
        if (!$hasTime) {
            // Verifica se foi originalmente DATE (sem tempo) — retorna DATE_FORMAT
            // Para determinismo por banco: Oracle DATE inclui hora, mas normalizamos igual
            // Se hora zero, preserva como DATE
            return $value->format(self::DATE_FORMAT);
        }

        // TIMESTAMP -> yyyy-MM-dd HH:mm:ss.SSS (milis com 3 dígitos)
        $millis = substr($value->format('u'), 0, 3);
        // Pad com zeros se driver não retornou micros
        $millis = str_pad($millis, 3, '0', STR_PAD_RIGHT);

        return $value->format('Y-m-d H:i:s') . '.' . $millis;
    }

    /**
     * Tenta normalizar string de data vinda de driver sem tipagem (sqlsrv/oracle/pgsql textual).
     * Retorna null se não for data.
     */
    private function tryNormalizeDateString(string $value, string $dbType): ?string
    {
        // Oracle DATE tipico: "2026-08-28 10:00:00" ou "28-AUG-26"
        // SQL Server: "2026-08-28 10:00:00.000"
        // Tenta formatos conhecidos; todos normalizam para mesmo golden
        $formats = [
            'Y-m-d H:i:s.v',
            'Y-m-d H:i:s.u',
            'Y-m-d H:i:s',
            'Y-m-d',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:s.v',
            'Y-m-d\TH:i:sP',
            'd-M-y', // Oracle compact
            'd-M-Y',
        ];
        foreach ($formats as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $value);
            if ($dt && $dt->format($fmt) === $value) {
                return $this->formatDateTime($dt, $dbType);
            }
        }
        // Tenta parse livre mas apenas se contém dígito e separador de data
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || preg_match('/^\d{2}-[A-Z]{3}-\d{2,4}/i', $value)) {
            try {
                $dt = new \DateTimeImmutable($value);
                return $this->formatDateTime($dt, $dbType);
            } catch (\Throwable $ignored) {
            }
        }

        return null;
    }

    private function normalizeDecimal(float $value, string $dbType): mixed
    {
        // Evita notação científica divergente entre executores (pdo vs jdbc)
        // Golden: sem perda de zeros significativos mas sem ruído de ponto flutuante
        // Para informix/oracle NUMBER high-precision, driver já retorna string — aqui apenas float path
        $str = (string) $value;
        // Se contém E, normaliza para decimal plain com 10 casas e trim
        if (stripos($str, 'e') !== false) {
            $str = sprintf('%.10F', $value);
        }
        // Remove trailing zeros mas preserva pelo menos um decimal se era float
        if (str_contains($str, '.')) {
            $str = rtrim(rtrim($str, '0'), '.');
            // Se virou inteiro após trim, retorna int para determinismo numerico? Mantém float semântica: retorna string? 
            // Mantém como float para json_encode como number — reconverte
            if (!str_contains($str, '.')) {
                return (float) $str;
            }

            return (float) $str;
        }

        return $value;
    }

    private function isBinary(string $value): bool
    {
        // Heurística: contém null byte ou não é UTF-8 válido (ResultSetUtil.java:150-164 bytes)
        if (str_contains($value, "\0")) {
            return true;
        }
        // Se contém caracteres de controle não-printable e não é UTF-8 válido
        if (!mb_check_encoding($value, 'UTF-8')) {
            return true;
        }
        // Sequência com bytes > 127 isolados não formando UTF-8 — já capturado acima
        return false;
    }

    private function isHighPrecisionDecimalString(string $value): bool
    {
        // NUMERIC/DECIMAL com alta precisão: > 15 dígitos significativos ou > 4 casas decimais com trailing zeros relevantes
        if (!is_numeric($value) || !str_contains($value, '.')) {
            return false;
        }
        // Conta dígitos significativos sem sinal e sem ponto
        $digits = preg_replace('/[^0-9]/', '', $value);
        if (strlen($digits) > 15) {
            return true;
        }
        // Preserva decimais com escala fixa (ex: 10.0000) que perderiam zeros em float
        if (preg_match('/\.\d*0$/', $value)) {
            // Se tem zeros à direita, float os perderia — preserva string para golden
            return true;
        }

        return false;
    }

    /**
     * Aplica golden determinístico ordenando chaves e serializando json estável (opcional).
     * @return string JSON canônico ordenado para comparação real (RQ-035)
     */
    public function toGoldenJson(array $rows): string
    {
        // Ordena chaves de cada row alfabeticamente para determinismo entre bancos (oracle order vs pgsql)
        $canonical = array_map(function (array $row) {
            ksort($row);
            return $row;
        }, $rows);

        return json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
