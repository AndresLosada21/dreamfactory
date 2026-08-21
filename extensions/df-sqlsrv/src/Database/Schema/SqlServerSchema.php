<?php

namespace Yamaha\DreamFactory\SqlServer\Database\Schema;

use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\SqlDb\Database\Schema\SqlSchema;

class SqlServerSchema extends SqlSchema
{
    const LEFT_QUOTE_CHARACTER = '[';
    const RIGHT_QUOTE_CHARACTER = ']';

    public function getDefaultSchema()
    {
        $schema = $this->selectValue('SELECT SCHEMA_NAME() AS schema_name');

        return is_string($schema) ? trim($schema) : $schema;
    }

    public function getSchemas()
    {
        return $this->selectColumn(<<<'SQL'
SELECT DISTINCT s.name
FROM sys.schemas s
INNER JOIN (
    SELECT schema_id FROM sys.tables WHERE is_ms_shipped = 0
    UNION
    SELECT schema_id FROM sys.views WHERE is_ms_shipped = 0
) objects ON objects.schema_id = s.schema_id
WHERE s.name NOT IN ('sys', 'INFORMATION_SCHEMA')
ORDER BY s.name
SQL
        );
    }

    public function quoteSimpleTableName($name)
    {
        return static::LEFT_QUOTE_CHARACTER . str_replace(']', ']]', $name) . static::RIGHT_QUOTE_CHARACTER;
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
SELECT s.name AS schema_name, t.name AS resource_name
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE t.is_ms_shipped = 0
  AND s.name = :schema
ORDER BY t.name
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
SELECT s.name AS schema_name, v.name AS resource_name
FROM sys.views v
INNER JOIN sys.schemas s ON s.schema_id = v.schema_id
WHERE v.is_ms_shipped = 0
  AND s.name = :schema
ORDER BY v.name
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
SELECT c.name AS column_name,
       ty.name AS data_type,
       c.max_length,
       c.precision AS numeric_precision,
       c.scale AS numeric_scale,
       c.is_nullable,
       c.is_identity,
       c.column_id,
       dc.definition AS default_definition,
       COALESCE(ix.is_primary_key, 0) AS is_primary_key,
       ix.primary_key_ordinal,
       COALESCE(ix.is_unique, 0) AS is_unique,
       COALESCE(ix.is_index, 0) AS is_index
FROM sys.columns c
INNER JOIN sys.objects o ON o.object_id = c.object_id
INNER JOIN sys.schemas s ON s.schema_id = o.schema_id
INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
OUTER APPLY (
    SELECT MAX(CASE WHEN i.is_primary_key = 1 THEN 1 ELSE 0 END) AS is_primary_key,
           MIN(CASE WHEN i.is_primary_key = 1 THEN ic.key_ordinal END) AS primary_key_ordinal,
           MAX(CASE WHEN i.is_unique = 1 THEN 1 ELSE 0 END) AS is_unique,
           MAX(CASE WHEN i.index_id > 0 THEN 1 ELSE 0 END) AS is_index
    FROM sys.indexes i
    INNER JOIN sys.index_columns ic
        ON ic.object_id = i.object_id
       AND ic.index_id = i.index_id
    WHERE i.object_id = c.object_id
      AND ic.column_id = c.column_id
      AND i.is_hypothetical = 0
) ix
WHERE o.type IN ('U', 'V')
  AND s.name = :schema
  AND o.name = :table
ORDER BY c.column_id
SQL
            , [':schema' => $schema, ':table' => $table->resourceName]);

        $primaryKeys = [];
        foreach ($rows as $row) {
            $column = array_change_key_case((array) $row, CASE_LOWER);
            $dataType = strtolower((string) $column['data_type']);
            $size = $this->columnSize($dataType, $column['max_length'] ?? null);
            $precision = $this->nullableInteger($column['numeric_precision'] ?? null);
            $scale = $this->nullableInteger($column['numeric_scale'] ?? null);

            $field = new ColumnSchema(['name' => $column['column_name']]);
            $field->quotedName = $this->quoteColumnName($field->name);
            $field->dbType = $this->formatDbType($dataType, $size, $precision, $scale);
            $field->size = $size;
            $field->precision = $precision;
            $field->scale = $scale;
            $field->allowNull = $this->asBool($column['is_nullable'] ?? false);
            $field->autoIncrement = $this->asBool($column['is_identity'] ?? false);
            $field->isPrimaryKey = $this->asBool($column['is_primary_key'] ?? false);
            $field->isUnique = $this->asBool($column['is_unique'] ?? false);
            $field->isIndex = $this->asBool($column['is_index'] ?? false);
            $field->fixedLength = in_array($dataType, ['char', 'nchar', 'binary'], true);
            $field->supportsMultibyte = in_array($dataType, ['nchar', 'nvarchar', 'ntext'], true);
            $field->type = $this->mapType($dataType, $size, $precision);

            $default = $column['default_definition'] ?? null;
            $field->defaultValue = is_string($default) ? trim($default) : $default;

            if ($field->isPrimaryKey) {
                $primaryKeys[(int) ($column['primary_key_ordinal'] ?? 0)] = $field->name;
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

    private function columnSize($dataType, $maxLength)
    {
        $size = $this->nullableInteger($maxLength);
        if ($size === null || $size === -1) {
            return $size;
        }

        if (in_array($dataType, ['nchar', 'nvarchar'], true)) {
            return (int) ($size / 2);
        }

        return $size;
    }

    private function formatDbType($dataType, $size, $precision, $scale)
    {
        if (in_array($dataType, ['varchar', 'nvarchar', 'varbinary'], true) && $size === -1) {
            return $dataType . '(max)';
        }

        if (in_array($dataType, ['char', 'nchar', 'varchar', 'nvarchar', 'binary', 'varbinary'], true) && $size !== null) {
            return $dataType . '(' . $size . ')';
        }

        if (in_array($dataType, ['decimal', 'numeric'], true) && $precision !== null) {
            return $dataType . '(' . $precision . ',' . ($scale ?? 0) . ')';
        }

        return $dataType;
    }

    private function mapType($dataType, $size, $precision)
    {
        switch ($dataType) {
            case 'bit':
                return DbSimpleTypes::TYPE_BOOLEAN;
            case 'tinyint':
                return DbSimpleTypes::TYPE_TINY_INT;
            case 'smallint':
                return DbSimpleTypes::TYPE_SMALL_INT;
            case 'int':
                return DbSimpleTypes::TYPE_INTEGER;
            case 'bigint':
                return DbSimpleTypes::TYPE_BIG_INT;
            case 'decimal':
            case 'numeric':
                return DbSimpleTypes::TYPE_DECIMAL;
            case 'money':
            case 'smallmoney':
                return DbSimpleTypes::TYPE_MONEY;
            case 'float':
                return $precision === 53 ? DbSimpleTypes::TYPE_DOUBLE : DbSimpleTypes::TYPE_FLOAT;
            case 'real':
                return DbSimpleTypes::TYPE_FLOAT;
            case 'date':
                return DbSimpleTypes::TYPE_DATE;
            case 'time':
                return DbSimpleTypes::TYPE_TIME;
            case 'datetime':
            case 'smalldatetime':
            case 'datetime2':
                return DbSimpleTypes::TYPE_DATETIME;
            case 'datetimeoffset':
                return DbSimpleTypes::TYPE_TIMESTAMP_TZ;
            case 'timestamp':
            case 'rowversion':
            case 'binary':
            case 'varbinary':
            case 'image':
                return DbSimpleTypes::TYPE_BINARY;
            case 'text':
            case 'ntext':
                return DbSimpleTypes::TYPE_TEXT;
            case 'varchar':
            case 'nvarchar':
                return $size === -1 ? DbSimpleTypes::TYPE_TEXT : DbSimpleTypes::TYPE_STRING;
            case 'uniqueidentifier':
                return DbSimpleTypes::TYPE_UUID;
            default:
                return DbSimpleTypes::TYPE_STRING;
        }
    }

    private function nullableInteger($value)
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function asBool($value)
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
