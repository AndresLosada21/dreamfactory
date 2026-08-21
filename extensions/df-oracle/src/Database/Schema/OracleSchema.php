<?php

namespace Yamaha\DreamFactory\Oracle\Database\Schema;

use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\SqlDb\Database\Schema\SqlSchema;

class OracleSchema extends SqlSchema
{
    const LEFT_QUOTE_CHARACTER = '"';
    const RIGHT_QUOTE_CHARACTER = '"';

    public function getDefaultSchema()
    {
        $schema = $this->selectValue("SELECT SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') AS schema_name FROM dual");

        return is_string($schema) ? trim($schema) : $schema;
    }

    public function getSchemas()
    {
        return $this->selectColumn(<<<'SQL'
SELECT owner
FROM (
    SELECT owner FROM all_tables
    UNION
    SELECT owner FROM all_views
)
ORDER BY owner
SQL
        );
    }

    public function quoteSimpleTableName($name)
    {
        return static::LEFT_QUOTE_CHARACTER . str_replace('"', '""', $name) . static::RIGHT_QUOTE_CHARACTER;
    }

    public function quoteSimpleColumnName($name)
    {
        return $this->quoteSimpleTableName($name);
    }

    protected function getTableNames($schema = '')
    {
        $schema = $this->resolveSchema($schema);
        if ($schema === '') {
            return [];
        }

        $rows = $this->connection->select(<<<'SQL'
SELECT owner AS schema_name, table_name AS resource_name
FROM all_tables
WHERE owner = :schema
ORDER BY table_name
SQL
            , [':schema' => $schema]);

        return $this->makeTableSchemas($rows, false);
    }

    protected function getViewNames($schema = '')
    {
        $schema = $this->resolveSchema($schema);
        if ($schema === '') {
            return [];
        }

        $rows = $this->connection->select(<<<'SQL'
SELECT owner AS schema_name, view_name AS resource_name
FROM all_views
WHERE owner = :schema
ORDER BY view_name
SQL
            , [':schema' => $schema]);

        return $this->makeTableSchemas($rows, true);
    }

    protected function loadTableColumns(TableSchema $table)
    {
        $schema = $table->schemaName ?: $this->getDefaultSchema();
        if (empty($schema)) {
            return;
        }

        $rows = $this->connection->select(<<<'SQL'
SELECT c.column_name,
       c.data_type,
       c.data_length,
       c.char_length,
       c.data_precision,
       c.data_scale,
       c.nullable,
       c.data_default,
       c.column_id,
       CASE WHEN pk.column_name IS NULL THEN 0 ELSE 1 END AS is_primary_key,
       pk.position AS primary_key_position
FROM all_tab_columns c
LEFT JOIN (
    SELECT cc.owner, cc.table_name, cc.column_name, cc.position
    FROM all_constraints ac
    INNER JOIN all_cons_columns cc
        ON cc.owner = ac.owner
       AND cc.constraint_name = ac.constraint_name
    WHERE ac.constraint_type = 'P'
) pk
    ON pk.owner = c.owner
   AND pk.table_name = c.table_name
   AND pk.column_name = c.column_name
WHERE c.owner = :schema
  AND c.table_name = :table_name
ORDER BY c.column_id
SQL
            , [':schema' => $schema, ':table_name' => $table->resourceName]);

        $primaryKeys = [];
        foreach ($rows as $row) {
            $column = array_change_key_case((array) $row, CASE_LOWER);
            $dataType = strtoupper((string) $column['data_type']);
            $precision = $this->nullableInteger($column['data_precision'] ?? null);
            $scale = $this->nullableInteger($column['data_scale'] ?? null);
            $size = $this->columnSize($dataType, $column, $precision);

            $field = new ColumnSchema(['name' => $column['column_name']]);
            $field->quotedName = $this->quoteColumnName($field->name);
            $field->dbType = $dataType;
            $field->size = $size;
            $field->precision = $precision;
            $field->scale = $scale;
            $field->allowNull = strtoupper((string) $column['nullable']) === 'Y';
            $field->isPrimaryKey = (int) ($column['is_primary_key'] ?? 0) === 1;
            $field->fixedLength = in_array($dataType, ['CHAR', 'NCHAR'], true);
            $field->supportsMultibyte = in_array($dataType, ['NCHAR', 'NVARCHAR2', 'NCLOB'], true);
            $field->type = $this->mapType($dataType, $precision, $scale, $size);

            $default = $column['data_default'] ?? null;
            $field->defaultValue = is_string($default) ? trim($default) : $default;

            if ($field->isPrimaryKey) {
                $primaryKeys[(int) ($column['primary_key_position'] ?? 0)] = $field->name;
            }

            $table->addColumn($field);
        }

        ksort($primaryKeys, SORT_NUMERIC);
        foreach ($primaryKeys as $name) {
            $table->addPrimaryKey($name);
        }
    }

    private function resolveSchema($schema)
    {
        if (is_array($schema)) {
            $schema = reset($schema);
        }

        $schema = trim((string) $schema);

        return $schema !== '' ? $schema : trim((string) $this->getDefaultSchema());
    }

    private function makeTableSchemas(array $rows, $isView)
    {
        $names = [];
        $defaultSchema = (string) $this->getDefaultSchema();

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $schemaName = (string) ($row['schema_name'] ?? '');
            $resourceName = (string) ($row['resource_name'] ?? '');
            if ($schemaName === '' || $resourceName === '') {
                continue;
            }

            $internalName = $schemaName . '.' . $resourceName;
            $name = strcasecmp($schemaName, $defaultSchema) === 0 ? $resourceName : $internalName;
            $settings = [
                'schemaName' => $schemaName,
                'resourceName' => $resourceName,
                'name' => $name,
                'internalName' => $internalName,
                'quotedName' => $this->quoteTableName($internalName),
                'leftQuoteCharacter' => static::LEFT_QUOTE_CHARACTER,
                'rightQuoteCharacter' => static::RIGHT_QUOTE_CHARACTER,
                'isView' => $isView,
            ];
            $names[strtolower($name)] = new TableSchema($settings);
        }

        return $names;
    }

    private function columnSize($dataType, array $column, $precision)
    {
        if (in_array($dataType, ['CHAR', 'VARCHAR2', 'NCHAR', 'NVARCHAR2'], true)) {
            return $this->nullableInteger($column['char_length'] ?? null);
        }

        if (in_array($dataType, ['NUMBER', 'DECIMAL', 'NUMERIC', 'FLOAT'], true)) {
            return $precision;
        }

        return $this->nullableInteger($column['data_length'] ?? null);
    }

    private function mapType($dataType, $precision, $scale, $size)
    {
        switch ($dataType) {
            case 'BOOLEAN':
                return DbSimpleTypes::TYPE_BOOLEAN;
            case 'NUMBER':
            case 'DECIMAL':
            case 'NUMERIC':
                if ($scale === 0 && $precision !== null && $precision <= 18) {
                    return DbSimpleTypes::TYPE_INTEGER;
                }

                return DbSimpleTypes::TYPE_DECIMAL;
            case 'BINARY_INTEGER':
            case 'PLS_INTEGER':
                return DbSimpleTypes::TYPE_INTEGER;
            case 'BINARY_FLOAT':
                return DbSimpleTypes::TYPE_FLOAT;
            case 'BINARY_DOUBLE':
                return DbSimpleTypes::TYPE_DOUBLE;
            case 'DATE':
                return DbSimpleTypes::TYPE_DATETIME;
            case 'TIMESTAMP':
                return DbSimpleTypes::TYPE_TIMESTAMP;
            case 'TIMESTAMP WITH TIME ZONE':
            case 'TIMESTAMP WITH LOCAL TIME ZONE':
                return DbSimpleTypes::TYPE_TIMESTAMP_TZ;
            case 'RAW':
            case 'LONG RAW':
            case 'BLOB':
            case 'BFILE':
                return DbSimpleTypes::TYPE_BINARY;
            case 'CLOB':
            case 'NCLOB':
            case 'LONG':
                return DbSimpleTypes::TYPE_TEXT;
            case 'JSON':
                return DbSimpleTypes::TYPE_JSON;
            default:
                return DbSimpleTypes::TYPE_STRING;
        }
    }

    private function nullableInteger($value)
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
