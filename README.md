<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa DevTools

> The **generate-verify-inspect** developer loop for the Milpa PHP framework: deterministic controller/entity scaffolding (the Make engine), boot-time doctors, and architectural validators — capability graphs, plugin manifests, boundary rules — that run **in-process**, with Composer-safe root resolution.

[![CI](https://github.com/getmilpa/devtools/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/devtools/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/devtools.svg)](https://packagist.org/packages/milpa/devtools)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/devtools/)

`milpa/devtools` is what `coa` calls when you type `coa:make`, `coa:doctor`, `coa:validate`, or
`coa:inspect` — the engine behind that whole CLI surface, extracted so it runs the same in-process
whether it's driven by a real host app's console or by your own tests. **Generate** deterministic,
convention-following scaffolding; **verify** it against the exact runtime rules the framework
enforces; **inspect** a plugin ecosystem's capability graph and manifests for problems before they
become a boot-time failure. No `exec()` to a script at a hardcoded path, no assumption about
install depth — just classes you can `new` and call.

## Install

```bash
composer require milpa/devtools
```

## Quick example: generate, then read what you got

`EntityGenerator`/`ControllerGenerator` both target **two conventions** — a Doctrine `Milpa\app`
legacy host, or a plain `milpa/data`/PSR-7 `milpa/runtime` host — auto-detected per app root by
`ConventionDetector` (override with `GenerationContext`'s `flavor` option, e.g. `--flavor=runtime`).
The full split, exact CLI syntax for each host, and the `--fields` DSL live in
[`docs/DEVTOOLS-MAKE.md`](../../docs/DEVTOOLS-MAKE.md) of the host monorepo; this README's example
below shows the **legacy** flavor. Either way it's a string, in memory, with zero disk I/O of its
own (that's `WriteGuard`'s job, so a caller can inspect, diff, or dry-run a generation before
anything touches the filesystem):

```php
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\EntityGenerator;

$context = new GenerationContext(
    plugin: 'InventoryPlugin',
    name: 'Product',
    options: ['fields' => 'name:string:120,price:decimal:10,2,active:bool'],
    root: '/path/to/host-app',
);

$result = (new EntityGenerator())->generate($context);

echo $result->files[0]->path;
// -> /path/to/host-app/plugins/InventoryPlugin/Entities/Product.php

echo $result->files[0]->contents;
```

produces (verbatim, this is a real run — see [What's inside](#whats-inside) for the full field DSL):

```php
<?php

declare(strict_types=1);

namespace Milpa\Plugins\InventoryPlugin\Entities;

use Doctrine\ORM\Mapping as ORM;
use Milpa\Support\UuidGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product
{
    use UuidGenerator;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'boolean')]
    private bool $active;

    public function __construct()
    {
        $this->uuid = self::generateUuid();
    }

    // ... getId()/getUuid(), plus a get*()/set*() pair per field (elided here — see the
    //     generated file above for the full, real output).
}
```

`$result->verifyKind` is `'entity'` and `$result->verifyTarget` is the class's FQCN — the exact two
values `VerifyRunner::run()` needs to close the loop (see below) once the file is actually written.

## Composer-safe root resolution: `RootResolver`

Every `coa:*` devtools command needs one thing before it can do anything else: the Milpa **host
application's** filesystem root — the directory holding its `composer.json`, `plugins/`,
`scripts/`. Computing that as `dirname(__DIR__, N)` from a command's own file only works while the
command lives at a fixed depth relative to the host; the moment this package is Composer-vendored
(`vendor/milpa/devtools/...`, any install depth, a global install), that walk lands under `vendor/`
instead — silently, since `plugins/` or `scripts/` just aren't found under the wrong root (or worse,
a same-named directory is found there instead).

`RootResolver::resolve()` tries three strategies, in order, and throws
`RootNotFoundException` — never a plausible-looking wrong path — if none of them lands:

1. **An explicit root passed to the constructor.** Host wiring always wins — e.g. a container
   binding the app root once from a known-good source, or a test fixture.
2. **`Composer\InstalledVersions::getRootPackage()['install_path']`.** The Composer-canonical answer
   to "where is the application that required me" — correct regardless of install depth, path-repo
   dev install vs. a real registry install, valid the instant Composer's generated autoloader is on
   the include path, which it always is for any Composer-managed PHP process (`composer-runtime-api`
   is a real dependency of this package, not an optional one — see [Requirements](#requirements)).
3. **Walk up from `getcwd()`** looking for the nearest ancestor `composer.json` — a last-resort
   fallback for the pathological case where Composer's own runtime API is unavailable.

```php
use Milpa\DevTools\Support\RootResolver;

// Tier 1: explicit root wins outright.
(new RootResolver('/srv/my-milpa-app'))->resolve();
// -> '/srv/my-milpa-app'

// Tier 2 (no explicit root): Composer\InstalledVersions::getRootPackage()['install_path'].
(new RootResolver())->resolve();
// -> the absolute path of whatever application actually required milpa/devtools —
//    correct whether that's a path-repo dev install or vendor/milpa/devtools in production.
```

## The loop: generate → verify → inspect

| Layer | Namespace | What it does |
|-------|-----------|---------------|
| **Generate** | `Make` | `GeneratorInterface` implementations (`ControllerGenerator`, `EntityGenerator`) render a `.php.stub` template into a `PlannedFile` (path + contents, no I/O yet). `FieldParser` reads the `--fields` DSL; `WriteGuard` refuses to clobber an existing file unless `--force`; `VerifyRunner` closes the loop by running the matching verifier against the freshly written class, in-process. |
| **Verify** | `Verify` | `ControllerVerifier` / `EntityVerifier` reflect an *already-autoloaded* class and check it against the framework's real runtime conventions — extends `BaseController`, calls `parent::__construct()`, correct `#[ORM\Column]` nullability, no debug output, no duplicate routes, and more. A `VerificationResult` never throws for a violation; it collects `errors` (fail the run) and `warnings` (advisory only). |
| **Inspect** | `Validators` | `PluginManifestValidator` checks one `milpa.json` against the plugin manifest shape. `CapabilityGraphValidator` checks an entire plugin ecosystem: every hard `requires` must be satisfied by some plugin's `provides`, and the dependency graph must be acyclic (unmet `suggests` degrade, they never fail). `ProviderImplementsValidator` autoloads every declared provider and asserts it really implements what it claims. `BoundaryValidator` runs host-supplied `BoundaryRule`s (which directories may not reference which namespaces) — the engine is generic, the rules are yours. |

A validator example, real output — a manifest with a non-semver `version` fails with a precise,
addressable message instead of a generic "invalid manifest":

```php
use Milpa\DevTools\Validators\PluginManifestValidator;

file_put_contents('/tmp/milpa.json', json_encode([
    'name' => 'acme/inventory',
    'version' => '1.0',                          // not semver — must be x.y.z
    'type' => 'Mixed',
    'namespace' => 'Milpa\\Plugins\\InventoryPlugin',
    'entrypoint' => 'InventoryPlugin.php',
]));

$result = (new PluginManifestValidator())->validate('/tmp/milpa.json');

$result->ok();      // false
$result->errors;    // ["version must be semver: '1.0'"]
```

**The generated code targets your host app's conventions, not this package's.** `ControllerGenerator`
and `ControllerVerifier` both know the exact FQCNs `Milpa\app\Providers\BaseController` /
`HttpResponse` and the `#[Route]` attribute — that convention belongs to a real Milpa host
application, not to `milpa/devtools` itself, which ships **zero** `use` imports of those classes
(the `.stub` templates reference them as generated-code *text*, not real dependencies). `coa:make
controller` scaffolds a class that targets those FQCNs; `ControllerVerifier` closes the loop by
checking generated output against that same convention.

## What's inside

| Namespace | What it provides |
|-----------|-------------------|
| `Milpa\DevTools\Make` | `GeneratorInterface`, `GenerationContext`/`GenerationResult`/`PlannedFile`, `ControllerGenerator`/`EntityGenerator` (each targets `Flavor::Runtime` or `Flavor::Legacy`, picked by `ConventionDetector`), `FieldParser`/`FieldSpec` (the `--fields` DSL: `name:type[:mods]`, `?` prefix for nullable, `enum:<Enum>`, `<name>:belongsTo:<Target>` — legacy-only), `StubRenderer`, `WriteGuard`, `VerifyRunner` |
| `Milpa\DevTools\Verify` | `VerifierInterface`, `VerificationResult`, `ControllerVerifier`, `EntityVerifier` |
| `Milpa\DevTools\Validators` | `PluginManifestValidator`, `CapabilityGraphValidator`, `ProviderImplementsValidator`, `BoundaryValidator` (+ `BoundaryRule`/`BoundaryRuleResult`/`BoundaryReport`) and each validator's typed result |
| `Milpa\DevTools\Support` | `RootResolver`/`RootNotFoundException`, `ClassNameExtractor` (file path → FQCN, no autoloading — lets a CLI accept either) |

Every public symbol carries a DocBlock; the full field DSL, every generator/verifier check, and
every validator's exact error messages are documented at the source and in the
[API reference](https://getmilpa.github.io/devtools/).

## Requirements

- PHP **≥ 8.3**
- [`milpa/data`](https://packagist.org/packages/milpa/data) — a genuine runtime `require`: the
  runtime entity path (`Milpa\Data\EntityInterface`/`RepositoryFactory` — the scaffold picks its backend from `storage.driver`: file, sqlite, mysql or memory) is always loadable once
  `milpa/devtools` itself is composer-installed. `milpa/core` still appears only in `require-dev`
  (docs tooling).
- [`doctrine/orm`](https://packagist.org/packages/doctrine/orm) **^3** — **optional** (`suggest`,
  not `require`): only the **legacy** entity path needs it — `EntityGenerator::generateLegacy()` and
  `EntityVerifier`'s legacy branch reflect real `#[ORM\Column]`/`#[ORM\JoinColumn]` attributes, they
  don't just pattern-match their names. Generating/verifying a legacy entity without it installed
  fails fast with one clear message instead of a crash deep in attribute reflection. The controller
  path (either flavor) and the runtime entity path never touch Doctrine.
- `composer-runtime-api` **^2.2** — the documented way to depend on `Composer\InstalledVersions`,
  which `RootResolver` uses as its second resolution tier

## Documentation

**Full API reference: [getmilpa.github.io/devtools](https://getmilpa.github.io/devtools/)** — generated
straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=devtools)**.
