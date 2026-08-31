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
        $this->assertSame([], $fields[0]->cases, 'an enum with no case list is referenced, not made');
    }

    public function testEnumWithDeclaredCasesCarriesThemAndTheirCommasDoNotSplitFields(): void
    {
        // The case list's commas must not be mistaken for the field separator, and the target must be
        // the bare class name with its cases carried separately — so the enum can be GENERATED.
        $fields = (new FieldParser())->parse('titulo:string, prioridad:enum:PrioridadTarea(baja,media,alta), ?fecha:date');

        $this->assertCount(3, $fields, 'the enum(…) commas do not split the field list');
        $this->assertSame('prioridad', $fields[1]->name);
        $this->assertSame('enum', $fields[1]->kind);
        $this->assertSame('PrioridadTarea', $fields[1]->target, 'the target is the bare class name');
        $this->assertSame(['baja', 'media', 'alta'], $fields[1]->cases);
        $this->assertSame('date', $fields[2]->columnType);
        $this->assertTrue($fields[2]->nullable);
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

    /**
     * And the refusal NAMES the valid types.
     *
     * The DSL says `int`/`bool` where Doctrine's column types say `integer`/`boolean`, so the column
     * name is the natural first guess — an agent scaffolding a CRUD guessed `integer` and the old
     * message gave it nothing to retry with. `int` appearing in the message is what turns a dead end
     * into a second attempt.
     */
    public function testTheRefusalNamesTheValidTypes(): void
    {
        try {
            (new FieldParser())->parse('cantidad:integer');
            $this->fail('an unknown type has to be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('int', $e->getMessage());
            $this->assertStringContainsString('datetime', $e->getMessage());
            $this->assertStringContainsString('belongsTo', $e->getMessage());
        }
    }
}
