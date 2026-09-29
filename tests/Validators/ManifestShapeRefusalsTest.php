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

namespace Milpa\DevTools\Tests\Validators;

use Milpa\DevTools\Validators\PluginManifestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shape a `milpa.json` can be wrong in is named, not just "invalid": each case writes one manifest wrong in one
 * way and asserts the exact sentence the validator gives back, and that a valid manifest around it says nothing else.
 */
final class ManifestShapeRefusalsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-devtools-shape-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return ['name' => 'acme/blog', 'version' => '1.2.3', 'type' => 'Service', 'namespace' => 'Acme\\Blog', 'entrypoint' => 'Blog.php'];
    }

    /** @return iterable<string, array{0: mixed, 1: list<string>}> */
    public static function wrongShapes(): iterable
    {
        yield 'a JSON array at the root' => ['["a"]', ['manifest root must be a JSON object']];
        yield 'an empty JSON array at the root' => ['[]', ['manifest root must be a JSON object']];
        yield 'a JSON number at the root' => ['3', ['manifest root must be a JSON object']];
        yield 'an empty JSON object' => ['{}', [
            'missing or empty required field: name',
            'missing or empty required field: version',
            'missing or empty required field: type',
            'missing or empty required field: namespace',
            'missing or empty required field: entrypoint',
        ]];
        yield 'not JSON at all' => ['{name:', ['invalid JSON: Syntax error']];
        yield 'an uppercase name' => [['name' => 'Acme/Blog'] + self::valid(), ["name must be composer-style vendor/plugin (lowercase): 'Acme/Blog'"]];
        yield 'a name with no vendor' => [['name' => 'blog'] + self::valid(), ["name must be composer-style vendor/plugin (lowercase): 'blog'"]];
        yield 'capabilities as a string' => [self::valid() + ['capabilities' => 'x'], ['capabilities must be an object']];
        yield 'contracts as a string' => [self::valid() + ['contracts' => 'x'], ['contracts must be an object']];
        yield 'capabilities.requires as a string' => [self::valid() + ['capabilities' => ['requires' => 'x']], ['capabilities.requires must be an array']];
        yield 'a capability record that is not an object' => [self::valid() + ['capabilities' => ['suggests' => ['x']]], ['capabilities.suggests[0] must be an object']];
        yield 'a required capability missing its constraint' => [
            self::valid() + ['capabilities' => ['requires' => [['id' => 'mail', 'interface' => 'Acme\\Mail\\Mailer']]]],
            ["capabilities.requires[0]: missing required key 'constraint'"],
        ];
        yield 'an interface that is not a FQCN' => [
            self::valid() + ['capabilities' => ['requires' => [['id' => 'mail', 'interface' => 'Mailer', 'constraint' => '^1.0']]]],
            ["capabilities.requires[0].interface is not a valid FQCN: 'Mailer'"],
        ];
        yield 'a provided service that is not a FQCN' => [
            self::valid() + ['capabilities' => ['provides' => [['id' => 'mail', 'interface' => 'Acme\\Mail\\Mailer', 'contractVersion' => '1.0.0', 'service' => 'Smtp']]]],
            ["capabilities.provides[0].service is not a valid FQCN: 'Smtp'"],
        ];
        yield 'a contract version that is not semver' => [
            self::valid() + ['capabilities' => ['provides' => [['id' => 'mail', 'interface' => 'Acme\\Mail\\Mailer', 'contractVersion' => 'one', 'service' => 'Acme\\Mail\\Smtp']]]],
            ["capabilities.provides[0].contractVersion must be semver: 'one'"],
        ];
        yield 'contracts.requires as a string' => [self::valid() + ['contracts' => ['requires' => 'x']], ['contracts.requires must be an array']];
        yield 'a legacy contract that is not a FQCN' => [self::valid() + ['contracts' => ['provides' => ['Mailer', 3]]], [
            'contracts.provides[0] is not a valid FQCN string',
            'contracts.provides[1] is not a valid FQCN string',
        ]];
    }

    /** @param list<string> $expected */
    #[DataProvider('wrongShapes')]
    public function testEachWrongShapeIsNamed(mixed $manifest, array $expected): void
    {
        file_put_contents($this->path, \is_string($manifest) ? $manifest : (string) json_encode($manifest));

        $result = (new PluginManifestValidator())->validate($this->path);

        self::assertSame($expected, $result->errors);
        self::assertFalse($result->ok());
    }

    public function testAWellFormedCapabilityManifestSaysNothing(): void
    {
        file_put_contents($this->path, (string) json_encode(self::valid() + [
            'capabilities' => [
                'provides' => [['id' => 'mail', 'interface' => '\\Acme\\Mail\\Mailer', 'contractVersion' => '1.0.0-rc.1', 'service' => 'Acme\\Mail\\Smtp']],
                'requires' => [['id' => 'log', 'interface' => 'Psr\\Log\\LoggerInterface', 'constraint' => '^3.0']],
            ],
            'contracts' => ['provides' => ['Acme\\Mail\\Mailer']],
        ]));

        self::assertSame([], (new PluginManifestValidator())->validate($this->path)->errors);
    }
}
