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

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\ArtifactListHandler;
use Milpa\DevTools\Operations\ContractHandler;
use Milpa\DevTools\Operations\ContractSearchHandler;
use Milpa\DevTools\Operations\DiscoverHandler;
use Milpa\DevTools\Operations\PackageArtifactsHandler;
use Milpa\DevTools\Operations\TestDiscoveryHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * F-DISC (greenhouse decisions/0183, primitive #4): finding has ONE shape — `discover` fans out to
 * the EXISTING finders and answers uniform rows, each naming the exact operation that answers in
 * full.
 *
 * The laws encoded here:
 *
 * - one call finds a made artifact AND a contract, with rows of ONE shape:
 *   `{kind, identity, path?, detail: {operation, arguments}}`;
 * - every row's `detail` names a REAL operation that, called with exactly those arguments,
 *   answers — the pointer is executed here, not admired;
 * - TRUTH-TIE: per kind, discover's identities EQUAL the underlying finder's identities
 *   verbatim — a fan-out that skipped a finder, or re-scanned on its own, goes red because the
 *   expectation is the finder's live answer;
 * - finding nothing is an ANSWER (`ok:true`, empty `found`, the queried kinds named), and an
 *   unknown kind is a refusal that names the valid set;
 * - the operation is additive: every pre-existing operation stays declared, untouched.
 */
final class DiscoverHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-discover-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Plugins/Billing/Entities', 0o775, true);
        mkdir($this->root . '/tests/Plugins/Billing', 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_PRETTY_PRINT),
        );

        // Two app artifacts sharing the stem the queries use — the artifact fan-out's fixture.
        $this->writeFile(
            'src/Plugins/Billing/Entities/Invoice.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Billing\\Entities;\n\nfinal class Invoice\n{\n}\n",
        );
        $this->writeFile(
            'src/Plugins/Billing/Entities/InvoiceLine.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Billing\\Entities;\n\nfinal class InvoiceLine\n{\n}\n",
        );

        // A behavioral judge — the test fan-out's fixture.
        $this->writeFile(
            'tests/Plugins/Billing/InvoiceTest.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Plugins\\Billing;\n\n"
            . "final class InvoiceTest extends \\PHPUnit\\Framework\\TestCase\n"
            . "{\n    /** Invoices carry their total. */\n"
            . "    public function testItCarriesATotal(): void { self::assertTrue(true); }\n}\n",
        );

        // An installed vendor package — the contract and package fan-outs' fixture.
        $this->writeFile(
            'vendor/acme/lib/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
        $this->writeFile(
            'vendor/acme/lib/src/InvoiceGateway.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib;\n\nfinal class InvoiceGateway\n{\n}\n",
        );
        $this->writeFile(
            'vendor/composer/autoload_psr4.php',
            "<?php\n\nreturn array(\n    'Acme\\\\Lib\\\\' => array(" . var_export($this->root . '/vendor/acme/lib/src', true) . "),\n);\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function handler(): DiscoverHandler
    {
        return new DiscoverHandler(new RootResolver($this->root));
    }

    /** One call, uniform rows — and every row's detail is a real call that answers when made. */
    public function testItFindsAnArtifactAndAContractInOneCallAndEveryDetailAnswers(): void
    {
        $result = $this->handler()->handle(['query' => 'Invoice']);

        self::assertTrue($result['ok']);
        self::assertSame('Invoice', $result['query']);
        self::assertSame(['artifact', 'contract', 'test', 'package'], $result['kinds']);
        self::assertSame(\count($result['found']), $result['total']);

        $kinds = array_unique(array_column($result['found'], 'kind'));
        self::assertContains('artifact', $kinds, 'the made artifact is found');
        self::assertContains('contract', $kinds, 'the vendor contract is found in the SAME call');
        self::assertContains('test', $kinds, 'the behavioral judge is found in the SAME call');

        foreach ($result['found'] as $row) {
            self::assertSame(
                \array_key_exists('path', $row) ? ['kind', 'identity', 'path', 'detail'] : ['kind', 'identity', 'detail'],
                array_keys($row),
                'ONE row shape — never a per-finder dialect',
            );
            self::assertSame(['operation', 'arguments'], array_keys($row['detail']));

            // The pointer is executed, not admired: the named operation answers those arguments.
            $answer = $this->call($row['detail']['operation'], $row['detail']['arguments']);
            self::assertTrue($answer['ok'], "detail of «{$row['identity']}» names a call that answers");
        }
    }

    /** TRUTH-TIE: per kind, discover's identities EQUAL the underlying finder's, verbatim. */
    public function testItsIdentitiesEqualTheUnderlyingFindersIdentitiesVerbatim(): void
    {
        $handler = $this->handler();
        $roots = new RootResolver($this->root);

        // artifact ← artifact:list. The query matches every declared artifact, so the tie is total.
        $artifacts = (new ArtifactListHandler($roots))->handle([]);
        self::assertTrue($artifacts['ok']);
        $expected = array_column($artifacts['artifacts'], 'fqcn');
        self::assertSame(
            ['App\\Plugins\\Billing\\Entities\\Invoice', 'App\\Plugins\\Billing\\Entities\\InvoiceLine'],
            $expected,
            'positive control: the finder itself sees the fixture',
        );
        $discovered = $handler->handle(['query' => 'Invoice', 'kinds' => ['artifact']]);
        self::assertSame($expected, array_column($discovered['found'], 'identity'));

        // contract ← contract:search, same query, verbatim.
        $matches = (new ContractSearchHandler($roots))->handle(['q' => 'Invoice']);
        self::assertTrue($matches['ok']);
        $discovered = $handler->handle(['query' => 'Invoice', 'kinds' => ['contract']]);
        self::assertSame(array_column($matches['matches'], 'fqcn'), array_column($discovered['found'], 'identity'));
        self::assertContains('Acme\\Lib\\InvoiceGateway', array_column($discovered['found'], 'identity'));

        // test ← test:list, verbatim.
        $tests = (new TestDiscoveryHandler($roots))->handleList([]);
        self::assertTrue($tests['ok']);
        $discovered = $handler->handle(['query' => 'Invoice', 'kinds' => ['test']]);
        self::assertSame(array_column($tests['tests'], 'fqcn'), array_column($discovered['found'], 'identity'));

        // package ← package:artifacts, when the query names an installed «vendor/name».
        $artifactsOfPackage = (new PackageArtifactsHandler($roots))->handle(['package' => 'acme/lib']);
        self::assertTrue($artifactsOfPackage['ok']);
        $discovered = $handler->handle(['query' => 'acme/lib', 'kinds' => ['package']]);
        self::assertSame(array_column($artifactsOfPackage['artifacts'], 'fqcn'), array_column($discovered['found'], 'identity'));
    }

    /** Finding nothing is an answer that names what was searched; an unknown kind is a refusal. */
    public function testEmptyIsANamedAnswerAndAnUnknownKindARefusal(): void
    {
        $empty = $this->handler()->handle(['query' => 'NothingDeclaresThisNameAnywhere']);
        self::assertTrue($empty['ok'], 'finding nothing is an answer, not an error');
        self::assertSame([], $empty['found']);
        self::assertSame(0, $empty['total']);
        self::assertSame(['artifact', 'contract', 'test', 'package'], $empty['kinds'], 'the queried kinds are named');

        // A query that is not «vendor/name» finds no package — an answer, not a refusal.
        $notAPackage = $this->handler()->handle(['query' => 'Invoice', 'kinds' => ['package']]);
        self::assertTrue($notAPackage['ok']);
        self::assertSame([], $notAPackage['found']);

        $unknown = $this->handler()->handle(['query' => 'Invoice', 'kinds' => ['artifact', 'recipe']]);
        self::assertFalse($unknown['ok']);
        self::assertStringContainsString('unknown kind «recipe»', (string) $unknown['error']);
        self::assertStringContainsString('artifact, contract, test, package', (string) $unknown['error'], 'the refusal names the valid set');

        $unnamed = $this->handler()->handle([]);
        self::assertFalse($unnamed['ok']);
        self::assertStringContainsString('query', (string) $unnamed['error']);

        $askedForNothing = $this->handler()->handle(['query' => 'Invoice', 'kinds' => []]);
        self::assertFalse($askedForNothing['ok'], 'an empty subset asks for nothing and is refused, not reinterpreted');
        self::assertStringContainsString('artifact, contract, test, package', (string) $askedForNothing['error']);
    }

    /** Routes one detail call to the operation the row named, with exactly the row's arguments. */
    private function call(string $operation, array $arguments): array
    {
        $roots = new RootResolver($this->root);

        return match ($operation) {
            'artifact:contract' => (new ContractHandler($roots))->handle($arguments),
            'contract:search' => (new ContractSearchHandler($roots))->handle($arguments),
            'test:show' => (new TestDiscoveryHandler($roots))->handleShow($arguments),
            'package:artifacts' => (new PackageArtifactsHandler($roots))->handle($arguments),
            default => self::fail("row detail names «{$operation}», which is not an operation this package declares"),
        };
    }

    private function writeFile(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
