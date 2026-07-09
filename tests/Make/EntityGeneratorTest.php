<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\EntityGenerator;

final class EntityGeneratorTest extends TestCase
{
    // Generators only ever compose $root into a returned path STRING (see PlannedFile) — they never
    // read/write it — so a synthetic value is honest here (no real "host app" exists in this suite).
    // Every context below pins 'flavor' => 'legacy' explicitly: this class exercises the LEGACY
    // (Doctrine) convention specifically — see EntityGeneratorRuntimeTest for the runtime one — and
    // since F3 auto-detects the flavor from the (real) filesystem under $root when no override is
    // given (mirroring ControllerGenerator/ConventionDetector), a nonexistent synthetic root would
    // otherwise be genuinely ambiguous and default to Flavor::Runtime — not what these tests are about.
    private string $root = '/fake/host';

    public function testGeneratesEntityWithScalarEnumAndRelation(): void
    {
        $ctx = new GenerationContext(
            plugin: 'WorkflowEnginePlugin',
            name: 'Widget',
            options: ['fields' => 'title:string:120, ?note:text, state:enum:GatePassageStatus, gate:belongsTo:GateDefinition', 'table' => 'workflow_widgets', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);

        $this->assertCount(1, $result->files);
        $file = $result->files[0];
        $this->assertStringEndsWith('/plugins/WorkflowEnginePlugin/Entities/Widget.php', $file->path);

        $code = $file->contents;
        $this->assertStringContainsString('namespace Milpa\\Plugins\\WorkflowEnginePlugin\\Entities;', $code);
        $this->assertStringContainsString("#[ORM\\Table(name: 'workflow_widgets')]", $code);
        $this->assertStringContainsString('use Milpa\\Support\\UuidGenerator;', $code);
        // scalar with length
        $this->assertStringContainsString("#[ORM\\Column(type: 'string', length: 120)]", $code);
        $this->assertStringContainsString('private string $title;', $code);
        // nullable text
        $this->assertStringContainsString('private ?string $note = null;', $code);
        // enum column + typed accessor
        $this->assertStringContainsString('private string $state;', $code);
        $this->assertStringContainsString('public function getState(): GatePassageStatus', $code);
        $this->assertStringContainsString('return GatePassageStatus::from($this->state);', $code);
        // ManyToOne
        $this->assertStringContainsString('#[ORM\\ManyToOne(targetEntity: GateDefinition::class)]', $code);
        $this->assertStringContainsString('private GateDefinition $gate;', $code);

        $this->assertSame('entity', $result->verifyKind);
        $this->assertSame('Milpa\\Plugins\\WorkflowEnginePlugin\\Entities\\Widget', $result->verifyTarget);
    }

    /**
     * Regression test for F3: a scalar `json` field (php type `array`) with no `@var` docblock trips
     * PHPStan L6's `missingType.iterableValue`. The generator must annotate it, matching real entities
     * like a host app entity with relations and defaults.
     */
    public function testJsonFieldGetsArrayVarDocblock(): void
    {
        $ctx = new GenerationContext(
            plugin: 'WorkflowEnginePlugin',
            name: 'Widget',
            options: ['fields' => 'metadata:json, ?extra:json', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        $this->assertStringContainsString('/** @var array<string, mixed> */', $code);
        $this->assertStringContainsString('private array $metadata;', $code);

        $this->assertStringContainsString('/** @var array<string, mixed>|null */', $code);
        $this->assertStringContainsString('private ?array $extra = null;', $code);
    }

    /**
     * Regression test for F-N1 (BLOCKER): a nullable enum field (`?state:enum:ReviewState`) must emit
     * a parseable, correctly-nullable property + column — not `private string $state = null;` (a PHP
     * fatal: default null on a non-nullable native type) with a non-nullable column.
     */
    public function testNullableEnumFieldGeneratesParseableNullableColumn(): void
    {
        $ctx = new GenerationContext(
            plugin: 'WorkflowEnginePlugin',
            name: 'Widget',
            options: ['fields' => '?state:enum:GatePassageStatus', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $result = (new EntityGenerator())->generate($ctx);
        $code = $result->files[0]->contents;

        // column: nullable + type carried through
        $this->assertStringContainsString("#[ORM\\Column(type: 'string', nullable: true)]", $code);
        // property: nullable native type with a legal `= null` default
        $this->assertStringContainsString('private ?string $state = null;', $code);
        $this->assertStringNotContainsString('private string $state = null;', $code);

        // accessors: nullable enum type on both getter and setter
        $this->assertStringContainsString('public function getState(): ?GatePassageStatus', $code);
        $this->assertStringContainsString('return $this->state === null ? null : GatePassageStatus::from($this->state);', $code);
        $this->assertStringContainsString('public function setState(?GatePassageStatus $value): self', $code);
        $this->assertStringContainsString('$this->state = $value?->value;', $code);
    }

    /**
     * Regression test for F-N3: `id`/`uuid` field names collide with the stub's built-in `$id`/`$uuid`
     * properties and cause a PHP redeclare fatal — reject them up front instead.
     */
    public function testReservedFieldNameIdIsRejected(): void
    {
        $ctx = new GenerationContext(
            plugin: 'WorkflowEnginePlugin',
            name: 'Widget',
            options: ['fields' => 'id:int', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\InvalidArgumentException::class);
        (new EntityGenerator())->generate($ctx);
    }

    /**
     * @see testReservedFieldNameIdIsRejected
     *
     * `uuid` is reserved LEGACY-only — the Doctrine stub has a built-in `$uuid` property, the
     * runtime one does not (see EntityGeneratorRuntimeTest).
     */
    public function testReservedFieldNameUuidIsRejectedCaseInsensitively(): void
    {
        $ctx = new GenerationContext(
            plugin: 'WorkflowEnginePlugin',
            name: 'Widget',
            options: ['fields' => 'UUID:string', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\InvalidArgumentException::class);
        (new EntityGenerator())->generate($ctx);
    }

    /**
     * F1: `doctrine/orm` moved from `require` to `suggest` (see composer.json) — the entity path
     * must fail with a clear, actionable message instead of a confusing crash when it's absent.
     * `doctrineAvailable: false` simulates that; the real detection is
     * {@see \Milpa\DevTools\Support\DoctrineAvailability::isAvailable()}.
     */
    public function testThrowsAClearErrorWhenDoctrineOrmIsNotInstalled(): void
    {
        $gen = new EntityGenerator(doctrineAvailable: false);
        $ctx = new GenerationContext(
            plugin: 'WorkflowEnginePlugin',
            name: 'Widget',
            options: ['fields' => 'title:string', 'flavor' => 'legacy'],
            root: $this->root,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require doctrine/orm');
        $gen->generate($ctx);
    }
}
