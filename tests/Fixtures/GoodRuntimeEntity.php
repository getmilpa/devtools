<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

use Milpa\Data\EntityInterface;

/**
 * A conventional RUNTIME-flavor entity — {@see \Milpa\DevTools\Tests\Verify\EntityVerifierTest}
 * expects this to verify clean against {@see \Milpa\DevTools\Make\Flavor::Runtime}. Mirrors the
 * shape {@see \Milpa\DevTools\Make\Generators\EntityGenerator}'s runtime path scaffolds (and
 * `milpa/data`'s own `Milpa\Data\Tests\Fixtures\TestEntity`).
 */
final readonly class GoodRuntimeEntity implements EntityInterface
{
    public function __construct(
        public int|string|null $id,
        public string $title,
        public ?string $note,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'note' => $this->note,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): static
    {
        return new self($row['id'] ?? null, $row['title'], $row['note']);
    }
}
