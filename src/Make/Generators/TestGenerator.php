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
 *
 * A name that already ends in `Test` IS the judge. Appending the suffix again invents a class the
 * landing gate cannot find (`TareaServiceTest` → `TareaServiceTestTest`) and the guidance used to
 * teach the resulting cycle: fill that phantom, then land a body that does not exist.
 */
final class TestGenerator implements GeneratorInterface
{
    /** The `<what>` token this generator answers to: `'test'`. */
    public function name(): string
    {
        return 'test';
    }

    /** Renders the judge scaffold and points the next step at the REAL target — never a cycle. */
    public function generate(GenerationContext $context): GenerationResult
    {
        $plugin = $context->plugin;
        [$judge, $target] = $this->judgeAndTarget($context->name);
        $path = "tests/Plugins/{$plugin}/{$judge}.php";

        $contents = <<<PHP
<?php

declare(strict_types=1);

namespace App\\Tests\\Plugins\\{$plugin};

use PHPUnit\\Framework\\TestCase;

/**
 * The behavioral judge of {@see \\App\\Plugins\\{$plugin}\\{$target}}.
 *
 * The landing gate runs this file whenever {$target} lands: red restores the original byte for
 * byte, green is named in the landing's verdict. Declare here what the class must DO — not what
 * it looks like; the gate already judges shape.
 */
final class {$judge} extends TestCase
{
    public function testDeclareWhatItMustDo(): void
    {
        // Replace this with real behavior. It fails ON PURPOSE: a judge that judges nothing must
        // not green-light anything — until this is real, no body of {$target} lands past the gate.
        self::fail('this judge does not judge anything yet — declare what {$target} must DO');
    }
}

PHP;

        return new GenerationResult(
            files: [new PlannedFile($path, $contents)],
            guidance: $this->guidance($context, $judge, $target),
        );
    }

    /**
     * Splits `$name` into the judge class and the production class it judges.
     *
     * `TareaService` and `TareaServiceTest` both yield judge `TareaServiceTest` / target
     * `TareaService`. A bare `Test` is a target named Test — stripping it would collapse the
     * remainder to empty.
     *
     * @return array{0: string, 1: string}
     */
    private function judgeAndTarget(string $name): array
    {
        $target = $name;
        if ($name !== 'Test' && str_ends_with($name, 'Test')) {
            $target = substr($name, 0, -\strlen('Test'));
        }

        return [$target . 'Test', $target];
    }

    /**
     * Names the real target. When that class is not on disk yet, the next step is to create it
     * (or implement judge and target together) — never "fill the judge, then land the body".
     */
    private function guidance(GenerationContext $context, string $judge, string $target): string
    {
        if ($this->targetExists($context, $target)) {
            return "This judge (class {$judge}) will run when {$target} lands. Fill it with `implement` declaring what {$target} must do.";
        }

        return "No {$target} exists yet in plugin {$context->plugin}. Create the target first, or implement the judge (class {$judge}) and {$target} together.";
    }

    /** True when `$target.php` already lives under `src/Plugins/<plugin>/`. */
    private function targetExists(GenerationContext $context, string $target): bool
    {
        $tree = $context->root . '/src/Plugins/' . $context->plugin;
        if (!is_dir($tree)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getFilename() === $target . '.php') {
                return true;
            }
        }

        return false;
    }
}
