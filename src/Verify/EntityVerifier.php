<?php

declare(strict_types=1);

namespace Milpa\DevTools\Verify;

use Doctrine\ORM\Mapping as ORM;
use Milpa\Data\EntityInterface;
use Milpa\DevTools\Make\Flavor;
use Milpa\DevTools\Support\DoctrineAvailability;
use ReflectionClass;
use ReflectionProperty;

/**
 * Verifies a class follows one of the two entity conventions {@see Flavor} names, chosen at
 * construction time (default {@see Flavor::Legacy}, matching this class's behavior before F3 — every
 * existing caller that builds `new EntityVerifier()` with no argument is unaffected), mirroring
 * exactly how {@see ControllerVerifier} picks its convention:
 *
 * - {@see Flavor::Legacy}: the Milpa host-app Doctrine entity convention — `#[ORM\Entity]` (+
 *   `#[ORM\Table]`), an `#[ORM\Id]`/`#[ORM\GeneratedValue]` identity, private-only properties, typed
 *   `#[ORM\Column]`s with nullable coherence between the column and the PHP type, initialized
 *   to-many collections, an `#[ORM\PreUpdate]` + `#[ORM\HasLifecycleCallbacks]` pair when
 *   `updatedAt` is present, and that Doctrine's no-constructor hydration path actually works
 *   (nullable properties readable right after `newInstanceWithoutConstructor()`). `doctrine/orm` is
 *   an OPTIONAL dependency of `milpa/devtools` (see `composer.json`'s `suggest`) — this is the only
 *   path that needs it. {@see verify()} returns a failed {@see VerificationResult} carrying
 *   {@see DoctrineAvailability::MESSAGE} when it is not installed, the same non-throwing shape as
 *   every other verification failure this class reports (e.g. "class not found" below), rather than
 *   crashing deep inside attribute reflection. Ported 1:1 from `scripts/verify-entity.php`
 *   (reflection checks only; CLI arg parsing/formatting lives in the thin shim).
 * - {@see Flavor::Runtime}: `milpa/data`'s plain-entity convention — implements
 *   `Milpa\Data\EntityInterface` (`id()`/`toArray()`/`fromArray()`), no Doctrine attributes. Unlike
 *   the legacy path there is no column/nullability/hydration bookkeeping to check — persistence
 *   lives entirely behind `Milpa\Data\RepositoryInterface`, outside this class. `milpa/data` is a
 *   hard `require` of `milpa/devtools` (unlike the optional `doctrine/orm`), so no availability
 *   guard is needed for this path.
 */
final class EntityVerifier implements VerifierInterface
{
    private bool $doctrineAvailable;

    /**
     * @param bool|null $doctrineAvailable override for testing; `null` (the default) auto-detects via
     *                                     {@see DoctrineAvailability::isAvailable()}
     */
    public function __construct(
        private readonly Flavor $flavor = Flavor::Legacy,
        ?bool $doctrineAvailable = null,
    ) {
        $this->doctrineAvailable = $doctrineAvailable ?? DoctrineAvailability::isAvailable();
    }

    /** Reflects `$fqcn` and checks it against the constructor-selected {@see Flavor}'s convention. */
    public function verify(string $fqcn): VerificationResult
    {
        if ($this->flavor === Flavor::Runtime) {
            if (!class_exists($fqcn)) {
                return new VerificationResult($fqcn, ["class not found: {$fqcn} — make sure the FQCN is correct and autoloadable"]);
            }

            return $this->verifyRuntime($fqcn);
        }

        if (!$this->doctrineAvailable) {
            return new VerificationResult($fqcn, [DoctrineAvailability::MESSAGE]);
        }

        if (!class_exists($fqcn)) {
            return new VerificationResult($fqcn, ["class not found: {$fqcn} — make sure the FQCN is correct and autoloadable"]);
        }

        return $this->verifyLegacy($fqcn);
    }

    private function verifyLegacy(string $fqcn): VerificationResult
    {
        $errors = [];
        $warnings = [];

        $reflection = new ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        $source = $file !== false ? file_get_contents($file) : false;

        if ($source !== false && !str_contains($source, 'declare(strict_types=1)')) {
            $errors[] = 'Missing declare(strict_types=1) at top of file';
        }

        if ($reflection->getAttributes(ORM\Entity::class) === []) {
            $errors[] = 'Missing #[ORM\\Entity] attribute on class';
        }

        if ($reflection->getAttributes(ORM\Table::class) === []) {
            $warnings[] = "Missing #[ORM\\Table(name: '...')] attribute — Doctrine will auto-generate a table name";
        }

        $hasId = false;
        $hasGeneratedValue = false;
        foreach ($reflection->getProperties() as $prop) {
            if ($prop->getAttributes(ORM\Id::class) !== []) {
                $hasId = true;
            }
            if ($prop->getAttributes(ORM\GeneratedValue::class) !== []) {
                $hasGeneratedValue = true;
            }
        }
        if (!$hasId) {
            $errors[] = 'Missing property with #[ORM\\Id] attribute';
        }
        if (!$hasGeneratedValue) {
            $warnings[] = 'Missing property with #[ORM\\GeneratedValue] — is this intentional (composite key)?';
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->class !== $fqcn) {
                continue;
            }
            $ormAttrs = array_merge(
                $prop->getAttributes(ORM\Column::class),
                $prop->getAttributes(ORM\Id::class),
                $prop->getAttributes(ORM\ManyToOne::class),
                $prop->getAttributes(ORM\OneToMany::class),
                $prop->getAttributes(ORM\ManyToMany::class),
                $prop->getAttributes(ORM\OneToOne::class),
            );
            if ($ormAttrs !== []) {
                $errors[] = "Property \${$prop->getName()} is public — Doctrine entities must use private properties with getters/setters";
            }
        }

        foreach ($reflection->getProperties() as $prop) {
            foreach ($prop->getAttributes(ORM\Column::class) as $attrRef) {
                $args = $attrRef->getArguments();
                if (!isset($args['type']) && !isset($args[0])) {
                    $warnings[] = "Property \${$prop->getName()}: #[ORM\\Column] missing 'type:' — Doctrine will infer from PHP type but being explicit is safer";
                }

                $col = $attrRef->newInstance();
                if ($col->nullable) {
                    $phpType = $prop->getType();
                    if ($phpType && !$phpType->allowsNull()) {
                        $errors[] = "Property \${$prop->getName()}: ORM\\Column(nullable: true) but PHP type '{$phpType}' does not allow null — change to '?{$phpType}'";
                    }
                    if ($phpType && $phpType->allowsNull() && !$prop->hasDefaultValue()) {
                        $errors[] = "Property \${$prop->getName()}: is nullable but missing default '= null' — causes 'must not be accessed before initialization'";
                    }
                }
            }
        }

        foreach ($reflection->getProperties() as $prop) {
            $isCollection = $prop->getAttributes(ORM\OneToMany::class) !== []
                || $prop->getAttributes(ORM\ManyToMany::class) !== [];
            if (!$isCollection) {
                continue;
            }

            $phpType = $prop->getType();
            if ($phpType && (string) $phpType === 'array' && (!$prop->hasDefaultValue() || $prop->getDefaultValue() !== [])) {
                $warnings[] = "Property \${$prop->getName()}: collection relation should be initialized to '= []' or via Doctrine ArrayCollection in constructor";
            }
        }

        $hasUpdatedAt = false;
        foreach ($reflection->getProperties() as $prop) {
            if (in_array($prop->getName(), ['updatedAt', 'updated_at'], true)) {
                $hasUpdatedAt = true;
                break;
            }
        }
        if ($hasUpdatedAt) {
            $hasPreUpdate = false;
            foreach ($reflection->getMethods() as $method) {
                if ($method->getAttributes(ORM\PreUpdate::class) !== []) {
                    $hasPreUpdate = true;
                    break;
                }
            }
            if (!$hasPreUpdate) {
                $warnings[] = "Entity has 'updatedAt' property but no method with #[ORM\\PreUpdate] — updatedAt will never be automatically set";
            }
            if ($reflection->getAttributes(ORM\HasLifecycleCallbacks::class) === []) {
                $warnings[] = 'Entity has lifecycle callback methods but is missing #[ORM\\HasLifecycleCallbacks] on the class';
            }
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            $errors[] = 'Cannot instantiate entity without constructor (Doctrine hydration would fail): ' . $e->getMessage();
            $instance = null;
        }

        if ($instance !== null) {
            foreach ($reflection->getProperties() as $prop) {
                $isNullableCol = false;
                foreach ($prop->getAttributes(ORM\Column::class) as $a) {
                    if ($a->newInstance()->nullable === true) {
                        $isNullableCol = true;
                    }
                }
                foreach ($prop->getAttributes(ORM\JoinColumn::class) as $a) {
                    if (($a->newInstance()->nullable ?? true) === true) {
                        $isNullableCol = true;
                    }
                }
                if (!$isNullableCol) {
                    continue;
                }
                $prop->setAccessible(true);
                try {
                    // The read itself is the check: an uninitialized typed property throws on
                    // access — the value, if it succeeds, is deliberately not needed.
                    $value = $prop->getValue($instance);
                    unset($value);
                } catch (\Error $e) {
                    if (str_contains($e->getMessage(), 'must not be accessed before initialization')) {
                        $errors[] = "Property \${$prop->getName()}: {$e->getMessage()}";
                    }
                }
            }
        }

        return new VerificationResult($reflection->getShortName(), $errors, $warnings);
    }

    /**
     * Checks the RUNTIME convention: a plain class implementing {@see EntityInterface}
     * (`id()`/`toArray()`/`fromArray()`, `fromArray()` static), carrying no Doctrine attributes —
     * the `milpa/data` convention {@see \Milpa\DevTools\Make\Generators\EntityGenerator}'s runtime
     * path scaffolds. Unlike {@see verifyLegacy()} there is no column/nullability/hydration
     * bookkeeping to check — persistence lives entirely behind `Milpa\Data\RepositoryInterface`,
     * outside this class.
     */
    private function verifyRuntime(string $fqcn): VerificationResult
    {
        $errors = [];
        $warnings = [];

        $reflection = new ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        $source = $file !== false ? file_get_contents($file) : false;

        if ($source !== false && !str_contains($source, 'declare(strict_types=1)')) {
            $errors[] = 'Missing declare(strict_types=1) at top of file';
        }

        if (!$reflection->implementsInterface(EntityInterface::class)) {
            $errors[] = 'Runtime entities must implement ' . EntityInterface::class
                . ' — that is the runtime convention; pass --flavor=legacy for a Doctrine entity instead';
        }

        if ($reflection->getAttributes(ORM\Entity::class) !== []) {
            $errors[] = 'Runtime entities must not carry #[ORM\\Entity] — that is the legacy convention; '
                . 'pass --flavor=legacy, or drop the attribute';
        }

        foreach (['id', 'toArray', 'fromArray'] as $methodName) {
            if (!$reflection->hasMethod($methodName)) {
                $errors[] = "Missing method {$methodName}() required by " . EntityInterface::class;
            }
        }

        if ($reflection->hasMethod('fromArray') && !$reflection->getMethod('fromArray')->isStatic()) {
            $errors[] = 'fromArray() must be a static method';
        }

        if ($reflection->hasMethod('id') && $reflection->getMethod('id')->isStatic()) {
            $errors[] = 'id() must not be static';
        }

        return new VerificationResult($reflection->getShortName() . ' — runtime entity', $errors, $warnings);
    }
}
