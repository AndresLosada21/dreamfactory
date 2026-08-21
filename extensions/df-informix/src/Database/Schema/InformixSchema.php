<?php

namespace Yamaha\DreamFactory\Informix\Database\Schema;

use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\SqlDb\Database\Schema\SqlSchema;

class InformixSchema extends SqlSchema
{
    const LEFT_QUOTE_CHARACTER = '"';
    const RIGHT_QUOTE_CHARACTER = '"';

    public function getDefaultSchema()
    {
        return trim((string) $this->getUserName());
    }

    public function getSchemas()
    {
        $schemas = $this->selectColumn(<<<'SQL'
SELECT DISTINCT tabowner
FROM systables
WHERE tabid >= 100
  AND tabtype IN ('T', 'E', 'V')
ORDER BY tabowner
SQL
        );

        return array_values(array_filter(array_map('trim', $schemas)));
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
        return $this->getRelationNames($schema, ['T', 'E'], false);
    }

    protected function getViewNames($schema = '')
    {
        return $this->getRelationNames($schema, ['V'], true);
    }

    protected function loadTableColumns(TableSchema $table)
    {
        $schema = $table->schemaName ?: $this->getDefaultSchema();
        if ($schema === '') {
            return;
        }

        $primaryKeys = $this->primaryKeyColumns($schema, $table->resourceName);
        $rows = $this->connection->select(<<<'SQL'
SELECT c.colname AS column_name,
       c.coltype AS column_type,
       c.collength AS column_length,
       c.colno AS column_position
FROM systables t
INNER JOIN syscolumns c ON c.tabid = t.tabid
WHERE t.tabid >= 100
  AND t.tabowner = :schema
  AND t.tabname = :table_name
ORDER BY c.colno
SQL
            , [':schema' => $schema, ':table_name' => $table->resourceName]);

        foreach ($rows as $row) {
            $column = array_change_key_case((array) $row, CASE_LOWER);
            $name = trim((string) ($column['column_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $typeCode = (int) ($column['column_type'] ?? 0);
            $baseType = $typeCode & 0xff;
            $length = (int) ($column['column_length'] ?? 0);
            $position = (int) ($column['column_position'] ?? -1);
            [$size, $precision, $scale] = $this->dimensions($baseType, $length);

            $field = new ColumnSchema(['name' => $name]);
            $field->quotedName = $this->quoteColumnName($name);
            $field->dbType = $this->databaseType($baseType);
            $field->type = $this->simpleType($baseType);
            $field->size = $size;
            $field->precision = $precision;
            $field->scale = $scale;
            $field->allowNull = ($typeCode & 0x100) === 0;
            $field->autoIncrement = in_array($baseType, [6, 18, 52, 53], true);
            $field->fixedLength = in_array($baseType, [0, 15], true);
            $field->supportsMultibyte = in_array($baseType, [15, 16], true);
            $field->isPrimaryKey = isset($primaryKeys[$position]);

            if ($field->isPrimaryKey) {
                $table->addPrimaryKey($name);
            }

            $table->addColumn($field);
        }
    }

    private function getRelationNames($schema, array $types, $isView)
    {
        $schema = $this->resolveSchema($schema);
        if ($schema === '') {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($types), '?'));
        $rows = $this->connection->select(
            <<<SQL
SELECT tabowner AS schema_name, tabname AS resource_name
FROM systables
WHERE tabid >= 100
  AND tabowner = ?
  AND tabtype IN ({$placeholders})
ORDER BY tabname
SQL,
            array_merge([$schema], $types)
        );

        return $this->makeTableSchemas($rows, $isView);
    }

    private function primaryKeyColumns($schema, $table)
    {
        $parts = implode(', ', array_map(static fn ($position) => 'i.part' . $position, range(1, 16)));
        $row = $this->connection->selectOne(
            <<<SQL
SELECT {$parts}
FROM sysconstraints c
INNER JOIN systables t ON t.tabid = c.tabid
INNER JOIN sysindexes i ON i.tabid = c.tabid AND i.idxname = c.idxname
WHERE t.tabowner = :schema
  AND t.tabname = :table_name
  AND c.constrtype = 'P'
SQL,
            [':schema' => $schema, ':table_name' => $table]
        );
        if (!$row) {
            return [];
        }

        $positions = [];
        foreach (array_values((array) $row) as $part) {
            $part = abs((int) $part);
            if ($part > 0) {
                $positions[$part - 1] = true;
            }
        }

        return $positions;
    }

    private function resolveSchema($schema)
    {
        if (is_array($schema)) {
            $schema = reset($schema);
        }

        $schema = trim((string) $schema);

        return $schema !== '' ? $schema : $this->getDefaultSchema();
    }

    private function makeTableSchemas(array $rows, $isView)
    {
        $names = [];
        $defaultSchema = $this->getDefaultSchema();

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $schemaName = trim((string) ($row['schema_name'] ?? ''));
            $resourceName = trim((string) ($row['resource_name'] ?? ''));
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

    private function dimensions($baseType, $length)
    {
        if (in_array($baseType, [5, 8], true)) {
            $precision = ($length >> 8) & 0xff;
            $scale = $length & 0xff;

            return [$precision ?: null, $precision ?: null, $precision ? $scale : null];
        }

        if (in_array($baseType, [0, 13, 15, 16, 40], true)) {
            return [$length > 0 ? $length : null, null, null];
        }

        return [null, null, null];
    }

    private function databaseType($baseType)
    {
        return match ($baseType) {
            0 => 'CHAR',
            1 => 'SMALLINT',
            2 => 'INTEGER',
            3 => 'FLOAT',
            4 => 'SMALLFLOAT',
            5 => 'DECIMAL',
            6 => 'SERIAL',
            7 => 'DATE',
            8 => 'MONEY',
            9 => 'NULL',
            10 => 'DATETIME',
            11 => 'BYTE',
            12 => 'TEXT',
            13 => 'VARCHAR',
            14 => 'INTERVAL',
            15 => 'NCHAR',
            16 => 'NVARCHAR',
            17 => 'INT8',
            18 => 'SERIAL8',
            19 => 'SET',
            20 => 'MULTISET',
            21 => 'LIST',
            22 => 'ROW',
            40 => 'LVARCHAR',
            41 => 'CLOB',
            42 => 'BLOB',
            43 => 'BOOLEAN',
            45 => 'BIGINT',
            52 => 'BIGSERIAL',
            53 => 'SERIAL16',
            default => 'INFORMIX_TYPE_' . $baseType,
        };
    }

    private function simpleType($baseType)
    {
        return match ($baseType) {
            1 => DbSimpleTypes::TYPE_SMALL_INT,
            2, 6 => DbSimpleTypes::TYPE_INTEGER,
            3 => DbSimpleTypes::TYPE_DOUBLE,
            4 => DbSimpleTypes::TYPE_FLOAT,
            5 => DbSimpleTypes::TYPE_DECIMAL,
            7 => DbSimpleTypes::TYPE_DATE,
            8 => DbSimpleTypes::TYPE_MONEY,
            10 => DbSimpleTypes::TYPE_DATETIME,
            11, 42 => DbSimpleTypes::TYPE_BINARY,
            12, 41 => DbSimpleTypes::TYPE_TEXT,
            13, 15, 16, 40 => DbSimpleTypes::TYPE_STRING,
            14 => DbSimpleTypes::TYPE_STRING,
            17, 18, 45, 52, 53 => DbSimpleTypes::TYPE_BIG_INT,
            19, 20, 21 => DbSimpleTypes::TYPE_ARRAY,
            22 => DbSimpleTypes::TYPE_ROW,
            43 => DbSimpleTypes::TYPE_BOOLEAN,
            default => DbSimpleTypes::TYPE_STRING,
        };
    }
}
