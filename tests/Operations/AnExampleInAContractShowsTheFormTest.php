<?php

/**
 * This file is part of milpa/devtools — the generate-verify-inspect developer loop of the Milpa
 * PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Composer\InstalledVersions;
use Milpa\Command\Operation;
use Milpa\DevTools\Operations\DevToolsOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AN EXAMPLE IS NOT THE EXAM (greenhouse decisions/0594 §5, question 7).
 *
 * A contract is read by every session, before it knows what it will be asked to build. When its
 * example of an argument is a real name — an entity, a route, a list of fields — the contract has
 * chosen a domain, and whoever is later asked for that very domain was handed part of the answer.
 * So an example shows the FORM of the value: a placeholder where a name would go (`<Entity>`,
 * `/<path>`, `<name>:string`), or a name this package can vouch for because it is its own.
 *
 * The rule is stated positively, and holds no list of names to avoid: such a list, written here,
 * would be the very thing it guards against.
 */
final class AnExampleInAContractShowsTheFormTest extends TestCase
{
    /** The words a form may carry around its placeholders: the field grammar `make` declares. */
    private const GRAMMAR = ['string', 'text', 'int', 'bigint', 'bool', 'float', 'decimal', 'date', 'datetime', 'json', 'enum', 'belongsTo', 'id'];

    /**
     * Names this package shows as they are, each for a reason that is checked below: a form written
     * as words, a fragment of the names its own search matches, and the namespace of a dependency.
     */
    private const OWN = ['domain:verb', 'Repository', 'Milpa\\Data\\'];

    public function testWhatFollowsAnExampleInAContractIsAForm(): void
    {
        $shown = 0;
        foreach ((new DevToolsOperations())->operations() as $operation) {
            foreach (self::contract($operation) as $where => $text) {
                foreach (self::shown($text) as $value) {
                    ++$shown;
                    self::assertTrue(
                        self::isAForm($value),
                        "{$where} shows «{$value}»: an example shows the form of the value — <Entity>, /<path>, <name>:string — or a name this package can vouch for",
                    );
                }
            }
        }

        self::assertGreaterThan(8, $shown, 'the control: the contracts do show examples');
    }

    /** The names shown as they are exist: the exception is a name the package answers for, not a habit. */
    public function testTheNamesShownAsTheyAreAreTheirOwn(): void
    {
        self::assertTrue(interface_exists('Milpa\\Data\\RepositoryInterface'), 'the interface the contract names, in the namespace and with the fragment it shows');
        self::assertTrue(InstalledVersions::isInstalled('milpa/data'), 'the package the contracts name');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function values(): iterable
    {
        yield 'a placeholder' => ['<Entity>', true];
        yield 'a path of placeholders' => ['/<path>', true];
        yield 'a field by its form' => ['<name>:string, ?<name>:date, <name>:bool', true];
        yield 'an enum by its form' => ['<name>:enum:<Class>(<case>,<case>,…)', true];
        yield 'a form written as words' => ['domain:verb', true];
        yield 'a class that exists' => ['Milpa\\Data\\RepositoryInterface', true];
        yield 'an installed package' => ['milpa/data', true];
        yield 'an entity by a name' => ['Thing', false];
        yield 'a route by a name' => ['/things', false];
        yield 'a field by a name' => ['size:decimal', false];
        yield 'a name beside a placeholder' => ['<name>:enum:Kind(<case>,…)', false];
        yield 'a package that is not there' => ['acme/things', false];
    }

    /** The control of the rule itself: it tells a form from a name, in both directions. */
    #[DataProvider('values')]
    public function testTheRuleTellsAFormFromAName(string $value, bool $form): void
    {
        self::assertSame($form, self::isAForm($value));
    }

    public function testItFindsTheValueAnExampleShows(): void
    {
        self::assertSame(['Thing'], self::shown('the entity it lists, e.g. Thing. With fields it is scaffolded'));
        self::assertSame(['/things'], self::shown('a literal path such as /things, no parameters'));
        self::assertSame(['size:decimal'], self::shown('fields (e.g. «size:decimal») and more'));
        self::assertSame(['things'], self::shown('lowercase letters and digits, e.g. things'));
        self::assertSame([], self::shown('the shared key (e.g. a name both sides agree on)'), 'words that describe are not a value');
        self::assertSame([], self::shown('nothing is shown here'));
    }

    /**
     * Every text of an operation's contract: its description, and each argument's.
     *
     * @return array<string, string>
     */
    private static function contract(Operation $operation): array
    {
        $texts = [$operation->name => $operation->description];
        $walk = static function (array $schema, string $path) use (&$walk, &$texts, $operation): void {
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                if (!\is_array($property)) {
                    continue;
                }
                if (\is_string($property['description'] ?? null)) {
                    $texts["{$operation->name} · {$path}{$name}"] = $property['description'];
                }
                $walk($property, "{$path}{$name}.");
                if (\is_array($property['items'] ?? null)) {
                    $walk($property['items'], "{$path}{$name}[].");
                }
            }
        };
        $walk($operation->inputSchema ?? [], '');

        return $texts;
    }

    /**
     * The values a text shows as examples: what follows "e.g.", "for example" or "such as", up to
     * the end of its clause. A value sits between « », backticks or double quotes, or is a bare
     * token shaped like code (a path, a Name, a name:type, a key=value) or standing alone. Words that
     * describe — "a name both sides agree on" — are not a value.
     *
     * @return list<string>
     */
    private static function shown(string $text): array
    {
        $values = [];
        preg_match_all('/(?:\be\.g\.,?|\bfor example,?|\bsuch as)\s+(.*?)(?=(?<![A-Za-z0-9\/])[.;](?:\s|$)| — |\)(?:[\s.,;]|$)|$)/isu', $text, $clauses);

        foreach ($clauses[1] as $clause) {
            $clause = trim($clause);
            if (preg_match_all('/«([^»]*)»|`([^`]*)`|"([^"]*)"/u', $clause, $quoted, \PREG_SET_ORDER) > 0) {
                foreach ($quoted as $q) {
                    $values[] = implode('', \array_slice($q, 1));
                }

                continue;
            }

            $first = rtrim((string) (preg_split('/[\s,]+/', $clause)[0] ?? ''), '.;:)');
            $alone = preg_match('/\s/', rtrim($clause, '.;:)')) !== 1;
            if ($first !== '' && ($alone || preg_match('/^[\/<{\[]|^[A-Z]|[:=_\\\\]/', $first) === 1)) {
                $values[] = $first;
            }
        }

        return $values;
    }

    /**
     * Whether a shown value is a form: it is made of placeholders and the grammar around them, or it
     * is a name this package can vouch for — a class that exists, an installed package, one of its own.
     */
    private static function isAForm(string $value): bool
    {
        if (\in_array($value, self::OWN, true) || class_exists($value) || interface_exists($value)) {
            return true;
        }
        if (preg_match('~^[a-z0-9-]+/[a-z0-9-]+$~', $value) === 1) {
            return InstalledVersions::isInstalled($value);
        }

        $rest = preg_replace('/<[^<>\s]+>|\{[^{}\s]+\}|…/u', '', $value) ?? $value;
        if ($rest === $value) {
            return false;
        }

        foreach (preg_split('/[^A-Za-z\\\\]+/', $rest, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (!\in_array($word, self::GRAMMAR, true)) {
                return false;
            }
        }

        return true;
    }
}
