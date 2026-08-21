<?php

namespace Yamaha\DreamFactory\NamedQuery\Tests;

use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;

class DefinitionCatalogTest extends TestCase
{
    public function testRuntimeCatalogHasAllTenMigratedQueries(): void
    {
        $expected = [
            'gq-eficaz.json' => ['gq_eficaz', ['gq-inspecao']],
            'gq-mi-pymac.json' => ['gq_mi_pymac', ['pymac-part-number', 'pymac-origin-destination']],
            'gq-mi-wms.json' => ['gq_mi_wms', ['wms-part-number']],
            'py-local.json' => ['py_local', ['gq-lote']],
            'pymac-ifx.json' => ['pymac_ifx', ['bom-plan']],
            'py-ptg.json' => ['py_ptg', ['acasala', 'chassi', 'motor']],
            'sgpi-hml.json' => ['sgpi_hml', ['bom-sgpi']],
        ];

        $compiler = new NamedSqlCompiler();
        $total = 0;

        foreach ($expected as $file => [$serviceName, $queryNames]) {
            $definition = json_decode(
                file_get_contents(__DIR__ . '/../database/definitions/' . $file),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame($serviceName, $definition['service_name']);
            self::assertSame($queryNames, array_column($definition['queries'], 'name'));

            foreach ($definition['queries'] as $query) {
                self::assertNotEmpty($query['sql']);
                self::assertNotEmpty($query['output_schema']);
                self::assertIsInt($query['budgets']['max_rows']);
                self::assertGreaterThan(0, $query['budgets']['max_rows']);

                $values = [];
                foreach ($query['parameters'] as $parameter) {
                    $values[$parameter['name']] = match ($parameter['type'] ?? 'string') {
                        'integer' => 1,
                        'number' => 1.5,
                        'boolean' => true,
                        default => 'test',
                    };
                }

                $compiled = $compiler->compile($query['sql'], $query['parameters'], $values);
                self::assertNotEmpty($compiled->sql);
                self::assertSame($this->parameterOccurrences($query['sql']), count($compiled->bindings));
                $total++;
            }
        }

        self::assertSame(10, $total);
    }

    private function parameterOccurrences(string $sql): int
    {
        preg_match_all(<<<'REGEX'
/(?:'[^']*(?:''[^']*)*'|"[^"]*(?:""[^"]*)*"|--[^\r\n]*|\/\*[\s\S]*?\*\/)(*SKIP)(*F)|(?<!:):[A-Za-z_][A-Za-z0-9_]*/
REGEX, $sql, $matches);

        return count($matches[0]);
    }
}
