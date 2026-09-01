<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa DevTools

> The **generate-verify-inspect** developer loop for the Milpa PHP framework: deterministic plugin/controller/entity/CRUD/service/tool scaffolding (the Make engine), boot-time doctors, and architectural validators — capability graphs, plugin manifests, boundary rules — that run **in-process**, with Composer-safe root resolution.

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

## The five atoms a host adopts: `DevToolsOperations`

The engine below is callable on its own, but a Milpa host normally adopts it as **declared
operations** — one `Operation` each for `validate`, `make`, `implement`, `edit` and `test`, enlisted
once and materialised by every surface the host projects to (terminal, HTTP, TUI, MCP). A host adds
`DevToolsOperations` to its operation providers and gets `coa make …` *and* a `make` tool its agent
can call, from the same declaration.

The five are a loop: scaffold it, **write its body**, check it follows the convention, then **run
it** to find out whether it also does what it was supposed to. Without the last the loop closes on
form and never on behaviour — an entity can satisfy `EntityInterface` perfectly and still return the
wrong field from `toArray()`.

### `implement` and `edit`: writing code through a gate, not around it

`make` scaffolds; these two fill. Both target **a class `make` already scaffolded** — the target is
a name the request can carry (the intent contract applies), and the path is derived by searching
only inside `src/Plugins/<plugin>/`, so escaping the tree is impossible by construction. Landing is
a **postcondition**: syntax on a staged copy, `strict_types`, the class it claims, the namespace its
location dictates — and, when the app ships PHPStan, **static conformance analysed in place**
(unknown collaborators and interface mismatches were the two measured ways a clean-parsing body
still failed to load). On any finding the original survives byte for byte and the diagnostic travels
back, which is what a model corrects from.

`implement` takes the complete file; `edit` takes find→replace pairs that must match **exactly
once** — measured on real sessions: re-generating a whole file is where a model's priors sneak back
in, and a pair that matches nothing returns the CURRENT file verbatim, so the next pair is built
against ground truth instead of memory.

And landing conformant is not behaving: when the class has its own test
(`tests/Plugins/<Plugin>/<Class>Test.php`), the gate **runs it** — the behavioural judge. A judge
is never asked to judge itself, so a TDD red is landable: `coa make test` scaffolds the judge
*failing on purpose* (a vacuous judge must not green-light an empty class), `implement` lands the
subject only when its judge passes. Measured with a real model in the loop: the judge converts
«shipped broken» into «correct or nothing».

```bash
coa make plugin Inventario Inventario --provides=inventory
coa make crud Inventario Producto --fields=sku:string,existencia:int
```

`make` takes `what` from **six** kinds — `plugin`, `controller`, `entity`, `crud`, `service`,
`tool` — plus the target plugin and the class name. With `what=plugin` the target *is* the artifact,
so both names are the same value. Everything else is optional and per-kind: `--fields`, `--route`,
`--methods`, `--table`, `--provides`, `--requires`, `--interface`, `--needs`, `--tool-name`,
`--description`, `--flavor`.

The result is a value, not a log line:

```php
[
    'ok'       => true,
    'files'    => [
        ['path' => '…/Plugins/Inventario/Entities/Producto.php',   'action' => 'created'],
        ['path' => '…/Plugins/Inventario/Controllers/…',           'action' => 'created'],
        ['path' => '…/Plugins/Inventario/Inventario.php',          'action' => 'merged'],
    ],
    'verify'   => ['ok' => true, 'output' => '…'],
    'guidance' => 'Auto-wired into the existing plugin at … (// {coa:services} marker found). …',
]
```

Four actions, and each says something different: `would-create` (a `dry_run` plan, nothing touched),
`created`, `merged` (grafted into an existing file at its marker — not an overwrite), and
`rolled-back` (written, then undone because the verify failed). `guidance` is the generator's own
next step — *"register it in config/plugins.php"*, *"no `// {coa:routes}` marker, add the 5 REST
routes by hand"* — and it is `null` when the write was rolled back, because pointing someone at
files that no longer exist is worse than saying nothing.

`make` declares `mutating: true` and does **not** require a signature: its blast radius is already
bounded by `WriteGuard` (never clobbers without `--force`) and by the rollback. `--force` overwrites
the artifact; it deliberately does *not* reach `MarkerInserter`, so forcing a regeneration can never
duplicate a service registration.

### `test` — run the host's suite

```bash
coa test --filter=ProductoTest
```

```php
['ok' => true, 'ran' => true, 'tests' => 12, 'assertions' => 34, 'failures' => 0, 'errors' => 0,
 'output' => '…', 'command' => '…/vendor/bin/phpunit --colors=never --filter ProductoTest']
```

The verdict is PHPUnit's **exit code**, not its text — the same thing a CI reads about the same run.
`ran` separates "the suite failed" from "the suite could not run" (no phpunit installed, a `path`
that escapes the project root): two different pieces of news, and confusing them means fixing the
wrong thing. Counts that cannot be read come back `null`, never `0` — zero tests and could-not-count
are different answers.

Unlike the other two, `test` declares `surfaces: ['cli', 'tui', 'mcp']`. A web request that fires the
app's own test suite is a surface nobody asked for: redundant in development, and a way to knock over
a deployed process from outside. It is also the one place in this package that spawns a subprocess —
a suite needs its own: inherited autoload state would make the result meaningless, and a fatal in one
test would take the caller down with it.

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
| **Generate** | `Make` | Six `GeneratorInterface` implementations — `PluginGenerator`, `ControllerGenerator`, `EntityGenerator`, `CrudGenerator`, `ServiceGenerator`, `ToolGenerator` — render a `.php.stub` template into a `PlannedFile` (path + contents, no I/O yet). The four composite ones also *graft* their wiring into an existing plugin at its `// {coa:*}` markers (`MarkerInserter`, idempotent: re-running does not duplicate the insertion), planning that file with `PlannedFile::$merge` so `WriteGuard` grafts instead of refusing. `FieldParser` reads the `--fields` DSL; `WriteGuard` refuses to clobber an existing file unless `--force`; `VerifyRunner` closes the loop by running the matching verifier against the freshly written class, in-process. |
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
| `Milpa\DevTools\Make` | `GeneratorInterface`, `GenerationContext`/`GenerationResult`/`PlannedFile`, the six generators — `PluginGenerator`, `ControllerGenerator`, `EntityGenerator`, `CrudGenerator`, `ServiceGenerator`, `ToolGenerator` (each targets `Flavor::Runtime` or `Flavor::Legacy`, picked by `ConventionDetector`; `plugin` is runtime-only), `MarkerInserter`/`Markers` (the `// {coa:services}`, `// {coa:routes}`, `// {coa:tools}` anchors composites graft into), `FieldParser`/`FieldSpec` (the `--fields` DSL: `name:type[:mods]`, `?` prefix for nullable, `enum:<Enum>`; `<name>:belongsTo:<Target>` creates a Doctrine relation only for a legacy entity, while a runtime resource accepts it solely as a named scalar-id degradation and runtime entity/CRUD callers must pass that scalar directly), `StubRenderer`, `WriteGuard`, `VerifyRunner` |
| `Milpa\DevTools\Verify` | `VerifierInterface`, `VerificationResult`, `ControllerVerifier`, `EntityVerifier` |
| `Milpa\DevTools\Validators` | `PluginManifestValidator`, `CapabilityGraphValidator`, `ProviderImplementsValidator`, `BoundaryValidator` (+ `BoundaryRule`/`BoundaryRuleResult`/`BoundaryReport`) and each validator's typed result |
| `Milpa\DevTools\Support` | `RootResolver`/`RootNotFoundException`, `ClassNameExtractor` (file path → FQCN, no autoloading — lets a CLI accept either), `ProcessRunner` (the one subprocess this package spawns: output *and* exit code, with a deadline) |

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
