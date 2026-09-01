<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Operations;

use Milpa\DevTools\Support\ComposerAutoload;
use Milpa\DevTools\Support\RootResolver;

/**
 * `artifact:contract` — a read-only look at what an artifact IS: for an enum, its backing type and
 * cases; for a class, its constructor signature and public methods; plus what it extends/implements.
 *
 * The debt this pays (greenhouse ROADMAP D1/D5/D6, from the audit of `work-mthqbzu6`): the agent was
 * discovering signatures and enum cases by TRIGGERING errors — an `implement` refusal that named the
 * missing constructor params, a static-analysis complaint that named the enum's unknown constant. An
 * error is not an introspection API. This is the explicit read: ask the contract, do not provoke it.
 *
 * The reach is universal on purpose. A bare name searches the app's own plugins; a name with a
 * backslash is a FQCN and resolves through the app's autoloader, which reaches installed vendor
 * code — the handler runs inside the host app process, so what the app can execute, the agent can
 * now also ASK about. Before this, installed packages existed for execution but not
 * epistemologically, and an agent burned turns deducing APIs it could have read.
 *
 * An optional `member` narrows the answer — the constructor, the method list, or one method — so a
 * narrow question gets a small answer instead of the whole contract.
 *
 * Not found is `ok:false` with a reason, never an exception — «I looked and it is not there» is a
 * valid observation about the target that was asked for (same contract as {@see ValidateHandler}).
 */
final class ContractHandler
{
    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * Resolves the named artifact and returns its contract, or `ok:false` when there is nothing to
     * read. Enums report their cases; classes report their constructor and methods. A FQCN (any name
     * with a backslash) resolves through the app's autoloader instead of the plugin tree, and
     * `member` narrows the answer to the constructor, the method list, or one method.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $name = \is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            return ['ok' => false, 'error' => 'name the artifact: artifact:contract needs a class/enum name'];
        }
        $member = \is_string($input['member'] ?? null) ? trim($input['member']) : '';

        if (str_contains($name, '\\')) {
            $fqcn = ltrim($name, '\\');
            if (! class_exists($fqcn) && ! enum_exists($fqcn) && ! interface_exists($fqcn)) {
                return [
                    'ok' => false,
                    'error' => "«{$fqcn}» is not loadable through this app's autoloader"
                        . ' — check the FQCN, or find the right name with contract:search',
                ];
            }

            return $this->contract($fqcn, $member);
        }

        $plugin = \is_string($input['plugin'] ?? null) ? trim($input['plugin']) : '';

        $root = $this->roots->resolve();
        [, $appDir] = ComposerAutoload::primaryNamespace($root) ?? ['App', 'src'];
        $base = $root . '/' . $appDir . '/Plugins/' . ($plugin !== '' ? $plugin : '*');

        $matches = array_merge(
            glob($base . '/' . $name . '.php') ?: [],
            glob($base . '/*/' . $name . '.php') ?: [],
        );
        if ($matches === []) {
            return [
                'ok' => false,
                'error' => "no artifact «{$name}»" . ($plugin !== '' ? " in plugin «{$plugin}»" : '')
                    . ' — make it first, or check the name',
            ];
        }

        $file = $matches[0];
        $source = (string) file_get_contents($file);
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $m) !== 1) {
            return ['ok' => false, 'error' => "«{$name}» has no namespace at {$file}"];
        }
        $fqcn = trim($m[1]) . '\\' . $name;

        if (! class_exists($fqcn) && ! enum_exists($fqcn) && ! interface_exists($fqcn)) {
            require_once $file;
        }
        if (! class_exists($fqcn) && ! enum_exists($fqcn) && ! interface_exists($fqcn)) {
            return ['ok' => false, 'error' => "«{$fqcn}» did not declare after loading {$file}"];
        }

        return $this->contract($fqcn, $member);
    }

    /**
     * Builds the resolved artifact's contract, whole or narrowed to one member.
     *
     * @return array<string, mixed>
     */
    private function contract(string $fqcn, string $member): array
    {
        if ($member !== '') {
            return $this->member($fqcn, $member);
        }

        return ['ok' => true, 'artifact' => enum_exists($fqcn) ? $this->enum($fqcn) : $this->classOrInterface($fqcn)];
    }

    /**
     * Answers one narrow question about the artifact instead of dumping its whole contract:
     * `constructor` returns only the constructor, `methods` only compact public signatures, and any
     * other value names one public method. A member that is not there is `ok:false` with a reason.
     *
     * @return array<string, mixed>
     */
    private function member(string $fqcn, string $member): array
    {
        $r = new \ReflectionClass($fqcn);
        $head = [
            'kind' => $r->isEnum() ? 'enum' : ($r->isInterface() ? 'interface' : 'class'),
            'fqcn' => $fqcn,
        ];

        if ($member === 'constructor') {
            if ($r->isEnum()) {
                return ['ok' => false, 'error' => "«{$fqcn}» is an enum — it has no constructor; ask without `member` for its cases"];
            }
            $ctor = $r->getConstructor();

            return ['ok' => true, 'artifact' => $head + [
                'constructor' => $ctor !== null ? array_map($this->param(...), $ctor->getParameters()) : null,
            ]];
        }

        if ($member === 'methods') {
            $signatures = [];
            foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (! $method->isConstructor()) {
                    $signatures[] = $this->signature($method);
                }
            }

            return ['ok' => true, 'artifact' => $head + ['methods' => $signatures]];
        }

        if (! $r->hasMethod($member) || ! $r->getMethod($member)->isPublic()) {
            return ['ok' => false, 'error' => "«{$fqcn}» has no public method «{$member}» — ask member=«methods» for what it offers"];
        }
        $method = $r->getMethod($member);

        return ['ok' => true, 'artifact' => $head + ['method' => [
            'name' => $method->getName(),
            'params' => array_map($this->param(...), $method->getParameters()),
            'returns' => $method->getReturnType() !== null ? (string) $method->getReturnType() : null,
            'static' => $method->isStatic(),
        ]]];
    }

    /** One compact `name(type $param, …): return` line — the smallest useful answer about a method. */
    private function signature(\ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $params[] = ($type !== null ? (string) $type . ' ' : '')
                . '$' . $param->getName()
                . ($param->isOptional() ? ' = …' : '');
        }
        $returns = $method->getReturnType();

        return ($method->isStatic() ? 'static ' : '')
            . $method->getName() . '(' . implode(', ', $params) . ')'
            . ($returns !== null ? ': ' . (string) $returns : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function enum(string $fqcn): array
    {
        $r = new \ReflectionEnum($fqcn);
        $cases = [];
        foreach ($r->getCases() as $case) {
            $cases[] = $case instanceof \ReflectionEnumBackedCase
                ? ['name' => $case->getName(), 'value' => $case->getBackingValue()]
                : ['name' => $case->getName()];
        }

        return [
            'kind' => 'enum',
            'fqcn' => $fqcn,
            'backing' => $r->isBacked() ? (string) $r->getBackingType() : null,
            'cases' => $cases,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classOrInterface(string $fqcn): array
    {
        $r = new \ReflectionClass($fqcn);

        $ctor = null;
        if ($r->getConstructor() !== null) {
            $ctor = array_map($this->param(...), $r->getConstructor()->getParameters());
        }

        $methods = [];
        foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $methods[] = [
                'name' => $method->getName(),
                'params' => array_map($this->param(...), $method->getParameters()),
                'returns' => $method->getReturnType() !== null ? (string) $method->getReturnType() : null,
                'static' => $method->isStatic(),
            ];
        }

        return [
            'kind' => $r->isInterface() ? 'interface' : 'class',
            'fqcn' => $fqcn,
            'extends' => ($p = $r->getParentClass()) !== false ? $p->getName() : null,
            'implements' => $r->getInterfaceNames(),
            'constructor' => $ctor,
            'methods' => $methods,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function param(\ReflectionParameter $p): array
    {
        $type = $p->getType();

        return [
            'name' => $p->getName(),
            'type' => $type !== null ? (string) $type : null,
            'nullable' => $type !== null && $type->allowsNull(),
            'optional' => $p->isOptional(),
            'default' => $p->isOptional() && $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
        ];
    }
}
