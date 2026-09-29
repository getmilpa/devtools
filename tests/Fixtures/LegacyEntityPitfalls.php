<?php

namespace Milpa\DevTools\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * The legacy (Doctrine) mistakes that pass `php -l` and bite at hydration — {@see \Milpa\DevTools\Tests\Verify\EntityVerifierPitfallsTest}
 * asserts each is named. No `declare(strict_types=1)` on purpose; a column with no `type:`; a nullable `?string` with no
 * `= null`; a collection typed `array` with no `= []`; an `updatedAt` nothing sets.
 */
#[ORM\Entity]
final class LegacyEntityPitfalls
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id = 0;

    #[ORM\Column]
    private string $title = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $subtitle;

    /** @var list<object> */
    #[ORM\OneToMany(targetEntity: GoodEntity::class, mappedBy: 'owner')]
    private array $comments;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;
}
