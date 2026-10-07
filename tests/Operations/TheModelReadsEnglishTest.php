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

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\DevToolsOperations;
use PHPUnit\Framework\TestCase;

/**
 * What the model reads of these operations is English — and an example is English above all.
 *
 * Measured on Rod's first live run (greenhouse evidence/1071, B11): `make`'s `fields` description gave
 * «titulo:string, ?fecha_limite:date, hecha:bool» as its example; the resident wrote `titulo:string,
 * cuerpo:text, publicada:bool` — the example, letter for letter — and built a public blog in Spanish. The
 * code-language ratchet counts code and comments, not schema strings, so this guard reads what the
 * model reads: every operation description and every description inside its input schema
 * (greenhouse decisions/0541).
 */
final class TheModelReadsEnglishTest extends TestCase
{
    /** Spanish marks no English description carries: its letters, and words English does not have. */
    private const SPANISH = '/[áéíóúñ¿¡]|\b(el|los|las|del|una|para|con|que|por|sin|campos|nombre|tipo|ruta|tabla|separad[oa]s|corre|archivo|clase)\b/iu';

    public function testEveryDescriptionAnOperationGivesTheModelIsEnglish(): void
    {
        $spanish = [];
        foreach ((new DevToolsOperations())->operations() as $operation) {
            foreach ([$operation->name => $operation->description, ...$this->descriptions($operation->inputSchema, $operation->name)] as $where => $text) {
                if (preg_match(self::SPANISH, $text) === 1) {
                    $spanish[] = "{$where}: {$text}";
                }
            }
        }

        self::assertSame([], $spanish, 'the model reads Spanish here');
    }

    /**
     * The example the resident copied is the one that has to be English — and, since a resident copies an
     * example letter for letter, it shows the form of a field and no field of its own (decisions/0594 §5).
     */
    public function testTheFieldsExampleIsEnglish(): void
    {
        $make = array_values(array_filter((new DevToolsOperations())->operations(), static fn ($o): bool => $o->name === 'make'))[0];
        $fields = (string) ($make->inputSchema['properties']['fields']['description'] ?? '');

        self::assertStringContainsString('<name>:string, ?<name>:date, <name>:bool', $fields);
        self::assertStringNotContainsString('titulo', $fields);
    }

    /**
     * Every `description` string inside a schema, keyed by where it sits.
     *
     * @param array<mixed> $schema
     *
     * @return array<string, string>
     */
    private function descriptions(array $schema, string $path): array
    {
        $found = [];
        foreach ($schema as $key => $value) {
            if ($key === 'description' && \is_string($value)) {
                $found[$path] = $value;
            } elseif (\is_array($value)) {
                $found += $this->descriptions($value, $path . '.' . $key);
            }
        }

        return $found;
    }
}
