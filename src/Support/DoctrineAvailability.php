<?php

declare(strict_types=1);

namespace Milpa\DevTools\Support;

/**
 * Guards the ENTITY path's optional dependency on `doctrine/orm` (see `composer.json`'s `suggest` —
 * it is deliberately NOT a hard `require`; the CONTROLLER path has zero Doctrine dependency, runtime
 * or legacy, and must stay that way). `EntityGenerator` and `EntityVerifier` both check this before
 * doing anything else so a host that scaffolds/verifies an entity without `doctrine/orm` installed
 * gets one clear, actionable failure instead of a confusing crash deep inside attribute reflection.
 */
final class DoctrineAvailability
{
    /** The shared, actionable failure message both `EntityGenerator` and `EntityVerifier` surface. */
    public const MESSAGE = 'doctrine/orm is required to scaffold or verify entities but is not installed — run: composer require doctrine/orm';

    private const MARKER_CLASS = 'Doctrine\\ORM\\Mapping\\Entity';

    /**
     * Whether `doctrine/orm`'s mapping attribute classes are loadable. A real `class_exists()` check
     * (not a `composer.json` inspection) so it reflects what will actually happen when the generated/
     * verified code runs, regardless of how it got there.
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::MARKER_CLASS);
    }
}
