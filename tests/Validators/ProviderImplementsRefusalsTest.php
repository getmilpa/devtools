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

use Milpa\DevTools\Validators\ProviderImplementsValidator;
use PHPUnit\Framework\TestCase;

/**
 * A declared provider is checked against the code that is actually loadable: real interfaces and classes of PHP itself
 * stand in for a plugin's, so each refusal is the one autoloading gives, not a string match.
 */
final class ProviderImplementsRefusalsTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function manifest(string $json): string
    {
        $file = sys_get_temp_dir() . '/milpa-devtools-provides-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, $json);

        return $this->files[] = $file;
    }

    /** @param array<string, string> $record */
    private function providing(array $record): string
    {
        return $this->manifest((string) json_encode(['capabilities' => ['provides' => [$record, 'not a record', ['interface' => 'Countable']]]]));
    }

    public function testAServiceThatImplementsItsInterfacePasses(): void
    {
        $result = (new ProviderImplementsValidator())->validate([$this->providing(['interface' => '\\Countable', 'service' => '\\ArrayObject'])]);

        self::assertTrue($result->ok());
        self::assertSame(1, $result->checked, 'records that are not objects, or name no service, are not counted');
    }

    public function testEachWayAProviderFailsIsNamed(): void
    {
        $noInterface = $this->providing(['interface' => 'Lab\\Nowhere\\Mailer', 'service' => 'ArrayObject']);
        $noService = $this->providing(['interface' => 'Countable', 'service' => 'Lab\\Nowhere\\Smtp']);
        $wrong = $this->providing(['interface' => 'Countable', 'service' => 'stdClass']);
        $notJson = $this->manifest('{');
        $legacy = $this->manifest((string) json_encode(['contracts' => ['provides' => ['Lab\\Nowhere\\Legacy', '\\Countable', 7]]]));

        $result = (new ProviderImplementsValidator())->validate([$noInterface, $noService, $wrong, $notJson, $legacy]);

        self::assertSame([
            "{$noInterface}: interface does not autoload — Lab\\Nowhere\\Mailer",
            "{$noService}: service does not autoload — Lab\\Nowhere\\Smtp",
            "{$wrong}: stdClass does not implement Countable",
            "{$notJson}: invalid JSON",
            "{$legacy}: declared provider interface does not autoload — Lab\\Nowhere\\Legacy",
        ], $result->violations);
        self::assertSame(5, $result->checked, 'three records and two legacy strings; the integer is skipped');
        self::assertFalse($result->ok());
    }
}
