<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\FieldParser;

final class FieldParserTest extends TestCase
{
    public function testParsesScalarsWithNullableAndModifiers(): void
    {
        $fields = (new FieldParser())->parse('title:string, ?subtitle:string:120, price:decimal:10,2, views:int, published:bool');

        $this->assertCount(5, $fields);

        $this->assertSame('title', $fields[0]->name);
        $this->assertSame('scalar', $fields[0]->kind);
        $this->assertSame('string', $fields[0]->phpType);
        $this->assertSame('string', $fields[0]->columnType);
        $this->assertFalse($fields[0]->nullable);

        $this->assertTrue($fields[1]->nullable);
        $this->assertSame(120, $fields[1]->modifiers['length']);

        $this->assertSame('string', $fields[2]->phpType);
        $this->assertSame('decimal', $fields[2]->columnType);
        $this->assertSame(10, $fields[2]->modifiers['precision']);
        $this->assertSame(2, $fields[2]->modifiers['scale']);

        $this->assertSame('int', $fields[3]->phpType);
        $this->assertSame('bool', $fields[4]->phpType);
    }

    public function testParsesEnumAndBelongsTo(): void
    {
        $fields = (new FieldParser())->parse('state:enum:OpportunityState, client:belongsTo:Client');

        $this->assertSame('enum', $fields[0]->kind);
        $this->assertSame('OpportunityState', $fields[0]->phpType);
        $this->assertSame('string', $fields[0]->columnType);
        $this->assertSame('OpportunityState', $fields[0]->target);

        $this->assertSame('belongsTo', $fields[1]->kind);
        $this->assertSame('Client', $fields[1]->phpType);
        $this->assertSame('Client', $fields[1]->target);
    }

    public function testEmptyDslIsNoFields(): void
    {
        $this->assertSame([], (new FieldParser())->parse('  '));
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown field type 'wat'");
        (new FieldParser())->parse('x:wat');
    }
}
