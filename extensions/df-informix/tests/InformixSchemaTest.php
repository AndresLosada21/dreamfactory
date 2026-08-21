<?php

namespace Yamaha\DreamFactory\Informix\Tests;

use DreamFactory\Core\Enums\DbResourceTypes;
use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\Informix\Database\Schema\InformixSchema;

class InformixSchemaTest extends TestCase
{
    public function testItBuildsInformixTableAndColumnMetadata(): void
    {
        $connection = new class {
            public function getConfig($key)
            {
                return $key === 'username' ? 'lymdaact' : null;
            }

            public function select($sql, $bindings = [])
            {
                if (str_contains($sql, 'FROM systables') && str_contains($sql, 'tabtype IN')) {
                    return [(object) ['schema_name' => 'lymdaact ', 'resource_name' => 'de_int_im ']];
                }
                if (str_contains($sql, 'FROM systables t') && str_contains($sql, 'syscolumns')) {
                    return [
                        (object) ['column_name' => 'itemno ', 'column_type' => 256, 'column_length' => 30, 'column_position' => 0],
                        (object) ['column_name' => 'serial_id ', 'column_type' => 262, 'column_length' => 4, 'column_position' => 1],
                        (object) ['column_name' => 'amount ', 'column_type' => 261, 'column_length' => 2562, 'column_position' => 2],
                    ];
                }

                self::fail('Unexpected catalog query.');
            }

            public function selectOne($sql, $bindings = [])
            {
                return (object) ['part1' => 1, 'part2' => 0];
            }
        };

        $schema = new InformixSchema($connection);
        $tables = $schema->getResourceNames(DbResourceTypes::TYPE_TABLE, 'lymdaact');

        self::assertArrayHasKey('de_int_im', $tables);
        $table = $tables['de_int_im'];
        $schema->getResource(DbResourceTypes::TYPE_TABLE, $table);

        self::assertSame('"lymdaact"."de_int_im"', $table->quotedName);
        self::assertSame(['itemno'], $table->primaryKey);
        self::assertTrue($table->getColumn('serial_id')->autoIncrement);
        self::assertSame(10, $table->getColumn('amount')->precision);
        self::assertSame(2, $table->getColumn('amount')->scale);
    }
}
