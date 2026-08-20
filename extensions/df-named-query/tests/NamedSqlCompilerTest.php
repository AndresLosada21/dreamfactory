<?php

namespace Yamaha\DreamFactory\NamedQuery\Tests;

use DreamFactory\Core\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Query\NamedSqlCompiler;

class NamedSqlCompilerTest extends TestCase
{
    public function testItBindsRepeatedParametersWithoutTouchingLiterals(): void
    {
        $compiled = (new NamedSqlCompiler())->compile(
            "SELECT ':vin' AS literal_value FROM vehicles WHERE vin = :vin OR previous_vin = :vin",
            [['name' => 'vin', 'required' => true]],
            ['vin' => '00123']
        );

        self::assertSame(
            "SELECT ':vin' AS literal_value FROM vehicles WHERE vin = :vin_0 OR previous_vin = :vin_1",
            $compiled->sql
        );
        self::assertSame(['vin_0' => '00123', 'vin_1' => '00123'], $compiled->bindings);
    }

    public function testItRejectsMutatingAndMultipleStatements(): void
    {
        $compiler = new NamedSqlCompiler();

        $this->expectException(BadRequestException::class);
        $compiler->compile('SELECT * FROM vehicles; DELETE FROM vehicles', [], []);
    }

    public function testItSupportsPostgreSqlCastsWithoutTreatingThemAsParameters(): void
    {
        $compiled = (new NamedSqlCompiler())->compile(
            'SELECT :vin::text AS vin',
            [['name' => 'vin', 'required' => true]],
            ['vin' => '00123']
        );

        self::assertSame('SELECT :vin_0::text AS vin', $compiled->sql);
        self::assertSame(['vin_0' => '00123'], $compiled->bindings);
    }

    public function testItRejectsSelectInto(): void
    {
        $this->expectException(BadRequestException::class);

        (new NamedSqlCompiler())->compile('SELECT * INTO archive FROM vehicles', [], []);
    }
}
