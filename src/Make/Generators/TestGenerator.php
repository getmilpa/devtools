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
 * ── THE JUDGE OF AN OPERATION HOLDS WHAT THE OPERATION WORKS THROUGH (greenhouse evidence/1156) ────
 *
 * The house says the judge comes before the body. Three real residents out of three, with their operations
 * scaffolded, spent their next fourteen to seventeen calls to the model finding out one thing the house knew: how
 * a test gets the repository `run()` receives. One never found it and filled no body. So when the class judged
 * is an operation of the plugin, the scaffold already builds that repository —in memory— and shows the call the
 * house makes. It still fails on purpose: what it must DO is the one thing no scaffold knows.
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
        $operation = $this->operation($context, $target);
        if ($operation !== null) {
            return new GenerationResult(
                files: [new PlannedFile($path, $this->judgeOfAnOperation($plugin, $judge, $target, $operation))],
                guidance: "This judge (class {$judge}) will run when {$target} lands. "
                    . ($operation['entity'] === null ? 'It shows' : "It already holds what {$target}::run() works through — the repository of "
                        . "{$operation['entity']}, in memory — and shows")
                    . " the call the house makes: fill it with `implement` declaring what {$target} must do. If it calls "
                    . "another operation of this plugin, land that one's body first: a judge runs over the house as it "
                    . 'is, and a scaffold answers ok: false.',
            );
        }

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
     * What the judge of an operation is scaffolded from, read from the operation's own source and from the entry
     * that lists it in its plugin — or `null` when the target is not an operation of this plugin.
     *
     * Read, never loaded: this runs in a copy of the house whose classes nothing autoloads here.
     *
     * @return array{fqcn: string, input: list<string>, optional: list<string>, hands: list<string>, entity: ?string, entityFqcn: ?string, repository: ?string}|null
     */
    private function operation(GenerationContext $context, string $target): ?array
    {
        $dir = $context->root . '/src/Plugins/' . $context->plugin;
        $file = $dir . '/Operations/' . $target . '.php';
        $source = is_file($file) ? (string) file_get_contents($file) : '';
        if (!str_contains($source, '#[Operation(') || preg_match('/^namespace ([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        $parameter = '/(\??[A-Za-z_\\\\][A-Za-z0-9_\\\\|]*)\s+\$(\w+)(\s*=)?/';
        $input = [];
        $optional = [];
        if (preg_match('/function __construct\((.*?)\)\s*\{/s', $source, $constructor) === 1) {
            preg_match_all($parameter, $constructor[1], $fields, PREG_SET_ORDER);
            foreach ($fields as $field) {
                if (($field[3] ?? '') !== '') {
                    $optional[] = $field[2];
                    continue;
                }
                $input[] = $field[2] . ': ' . self::sample($field[1]);
            }
        }

        $pluginFile = $dir . '/' . $context->plugin . '.php';
        $entityFqcn = is_file($pluginFile) ? OperationGenerator::entityHandedTo((string) file_get_contents($pluginFile), $target) : null;
        $hands = [];
        $repository = null;
        if (preg_match('/function run\((.*?)\)\s*:/s', $source, $run) === 1) {
            preg_match_all($parameter, $run[1], $takes, PREG_SET_ORDER);
            foreach ($takes as $taken) {
                if ($entityFqcn !== null && $repository === null && str_ends_with($taken[1], 'RepositoryInterface')) {
                    $repository = $taken[2];
                    $hands[] = '$this->' . $taken[2];
                    continue;
                }
                $hands[] = "/* {$taken[1]} \${$taken[2]} */";
            }
        }
        // The judge holds a repository only when run() takes one AND its plugin says of which entity.
        if ($repository === null) {
            $entityFqcn = null;
        }

        return [
            'fqcn' => $namespace[1] . '\\' . $target,
            'input' => $input,
            'optional' => $optional,
            'hands' => $hands,
            'entity' => $entityFqcn === null ? null : substr((string) strrchr('\\' . $entityFqcn, '\\'), 1),
            'entityFqcn' => $entityFqcn,
            'repository' => $repository,
        ];
    }

    /** A value of an input's type that the call can be made with — the judge's author replaces it. */
    private static function sample(string $type): string
    {
        return match (ltrim($type, '?')) {
            'string' => "'…'",
            'int' => '1',
            'float' => '1.0',
            'bool' => 'true',
            'array' => '[]',
            default => 'null /* ' . ltrim($type, '?') . ' */',
        };
    }

    /**
     * The judge of an operation: it holds what `run()` works through and shows the call the house makes.
     *
     * @param array{fqcn: string, input: list<string>, optional: list<string>, hands: list<string>, entity: ?string, entityFqcn: ?string, repository: ?string} $operation
     */
    private function judgeOfAnOperation(string $plugin, string $judge, string $target, array $operation): string
    {
        $uses = [$operation['fqcn'], 'PHPUnit\\Framework\\TestCase'];
        $holds = '';
        $after = '$answer';
        if ($operation['repository'] !== null && $operation['entityFqcn'] !== null) {
            array_push($uses, $operation['entityFqcn'], 'Milpa\\Data\\InMemoryRepository', 'Milpa\\Data\\RepositoryInterface');
            $after = "\$answer, and on what \$this->{$operation['repository']} holds afterwards";
            $holds = <<<PHP
    /**
     * What {$target}::run() works through, as the house hands it — in memory here: a test stores nothing.
     *
     * @var RepositoryInterface<{$operation['entity']}>
     */
    private RepositoryInterface \${$operation['repository']};

    protected function setUp(): void
    {
        \$this->{$operation['repository']} = new InMemoryRepository({$operation['entity']}::class);
    }


PHP;
        }
        sort($uses);
        $uses = implode("\n", array_map(static fn (string $class): string => "use {$class};", $uses));
        $input = implode(', ', $operation['input']);
        $hands = implode(', ', $operation['hands']);
        $optional = $operation['optional'] === [] ? '' : "        // Left out of that call because it is optional: " . implode(', ', $operation['optional']) . ".\n";

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Tests\\Plugins\\{$plugin};

{$uses}

/**
 * The behavioral judge of {@see {$target}}.
 *
 * The landing gate runs this file whenever {$target} lands: red restores the original byte for
 * byte, green is named in the landing's verdict. Declare here what the class must DO — not what
 * it looks like; the gate already judges shape.
 *
 * It runs over the house as it is when {$target} lands. If it calls another operation of this plugin,
 * that one's body has to be in the house first: a scaffold answers `ok: false`, and no judge goes green
 * over that.
 */
final class {$judge} extends TestCase
{
{$holds}    public function testDeclareWhatItMustDo(): void
    {
        // {$target} is called here the way the house calls it — built with its input, and its run()
        // handed what it works through:
        //
        //     \$answer = (new {$target}({$input}))->run({$hands});
        //
{$optional}        // Assert on {$after}.
        //
        // Replace this with real behavior. It fails ON PURPOSE: a judge that judges nothing must
        // not green-light anything — until this is real, no body of {$target} lands past the gate.
        self::fail('this judge does not judge anything yet — declare what {$target} must DO');
    }
}

PHP;
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
