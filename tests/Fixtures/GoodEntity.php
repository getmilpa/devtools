<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** A conventional Doctrine entity — {@see EntityVerifierTest} expects this to verify clean. */
#[ORM\Entity]
#[ORM\Table(name: 'good_entities')]
final class GoodEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 120)]
    private string $title;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $note = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
