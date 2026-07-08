<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Deliberately violates several `EntityVerifier` conventions at once — {@see EntityVerifierTest}
 * asserts each one is caught: missing `#[ORM\Entity]`, a public property carrying ORM attributes, and
 * a `nullable: true` column whose PHP type does not allow null.
 */
final class BadEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $name;
}
