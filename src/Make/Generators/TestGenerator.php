<?php

/**
 * This file is part of Milpa DevTools — the developer toolbox of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Make\Generators;

use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\GeneratorInterface;
use Milpa\DevTools\Make\PlannedFile;

/**
 * Scaffold the behavioral judge of a class: `tests/Plugins/<Plugin>/<Class>Test.php`.
 *
 * ── WHY THE SCAFFOLD FAILS ON PURPOSE ────────────────────────────────────────────────────────────
 *
 * The landing gate runs this judge whenever its subject lands (`implement`/`edit`). A scaffold that
 * passed vacuously — an empty method, a `markTestIncomplete` — would GREEN-LIGHT every body the
 * moment the file exists: a judge that judges nothing, wearing a verdict. So the scaffold's one
 * test is `self::fail(...)` with instructions: until somebody declares what the class must DO, no
 * body lands past it. The red is not a defect — it is TDD's starting position, made unskippable.
 *
 * The judge itself lands through the SAME gate (implement reaches `tests/Plugins/`), and the gate
 * knows a judge never judges itself — otherwise this red would be unlandable.
 */
final class TestGenerator implements GeneratorInterface
{
    /** The `<what>` token this generator answers to: `'test'`. */
    public function name(): string
    {
        return 'test';
    }

    /** Renders the judge scaffold and points the next step at `implement`. */
    public function generate(GenerationContext $context): GenerationResult
    {
        $plugin = $context->plugin;
        $class = $context->name;
        $path = "tests/Plugins/{$plugin}/{$class}Test.php";

        $contents = <<<PHP
<?php

declare(strict_types=1);

namespace App\\Tests\\Plugins\\{$plugin};

use PHPUnit\\Framework\\TestCase;

/**
 * The behavioral judge of {@see \\App\\Plugins\\{$plugin}\\{$class}}.
 *
 * The landing gate runs this file whenever {$class} lands: red restores the original byte for
 * byte, green is named in the landing's verdict. Declare here what the class must DO — not what
 * it looks like; the gate already judges shape.
 */
final class {$class}Test extends TestCase
{
    public function testDeclareWhatItMustDo(): void
    {
        // Replace this with real behavior. It fails ON PURPOSE: a judge that judges nothing must
        // not green-light anything — until this is real, no body of {$class} lands past the gate.
        self::fail('this judge does not judge anything yet — declare what {$class} must DO');
    }
}

PHP;

        return new GenerationResult(
            files: [new PlannedFile($path, $contents)],
            guidance: "Fill the judge with `implement` (class {$class}Test) — then land {$class}'s body: it will be judged.",
        );
    }
}
