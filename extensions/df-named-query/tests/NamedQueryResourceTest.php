<?php

namespace Yamaha\DreamFactory\NamedQuery\Tests;

use PHPUnit\Framework\TestCase;
use Yamaha\DreamFactory\NamedQuery\Resources\NamedQueryResource;

class NamedQueryResourceTest extends TestCase
{
    public function testItStopsCollectingRowsAtTheRevisionBudget(): void
    {
        $resource = new class extends NamedQueryResource {
            public function collect(iterable $rows, int $maxRows): array
            {
                return $this->collectRows($rows, $maxRows);
            }
        };

        self::assertSame(
            [['id' => 1], ['id' => 2]],
            $resource->collect((function () {
                yield (object) ['id' => 1];
                yield (object) ['id' => 2];
                self::fail('The result cursor was read beyond the configured row budget.');
            })(), 2)
        );
    }
}
