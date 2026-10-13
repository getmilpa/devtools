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

use Milpa\DevTools\Operations\SourceReadHandler;
use Milpa\DevTools\Support\RootResolver;
use Milpa\DevTools\Support\SecretFiles;
use PHPUnit\Framework\TestCase;

/**
 * The read that makes `edit` usable: a raw file slice with honest accounting, confined to the root.
 */
final class SourceReadHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-devtools-read-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o775, true);

        $lines = '';
        for ($number = 1; $number <= 10; ++$number) {
            $lines .= "line {$number}\n";
        }
        file_put_contents($this->root . '/src/Sample.php', $lines);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /** A slice comes back verbatim, with `from`, count, total, and an explicit truncation flag. */
    public function testReadsASliceWithHonestLineAccounting(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Sample.php', 'from' => 3, 'lines' => 4]);

        self::assertTrue($result['ok']);
        self::assertSame('src/Sample.php', $result['path']);
        self::assertSame(3, $result['from']);
        self::assertSame(4, $result['lines']);
        self::assertSame(10, $result['total_lines']);
        self::assertSame("line 3\nline 4\nline 5\nline 6\n", $result['content']);
        self::assertTrue($result['truncated'], 'lines 7..10 were left out and the answer says so');
    }

    /** By default the read starts at the top, and a whole file is declared un-truncated. */
    public function testDefaultsReadFromTheTopAndSayWhenNothingWasCut(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Sample.php']);

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['from']);
        self::assertSame(10, $result['lines']);
        self::assertFalse($result['truncated']);
        self::assertStringStartsWith("line 1\n", (string) $result['content']);
    }

    /** An absolute path is accepted when — and only when — it stays inside the root. */
    public function testAnAbsolutePathInsideTheRootWorks(): void
    {
        $result = $this->handler()->handle(['path' => $this->root . '/src/Sample.php']);

        self::assertTrue($result['ok']);
        self::assertSame('src/Sample.php', $result['path']);
    }

    /** A path that resolves outside the root is refused before anything is opened. */
    public function testATraversalOutsideTheRootIsRefused(): void
    {
        $outside = $this->root . '-outside.txt';
        file_put_contents($outside, 'secret');

        try {
            $relative = $this->handler()->handle(['path' => '../' . basename($outside)]);
            self::assertFalse($relative['ok'], 'a .. traversal must not escape the root');

            $absolute = $this->handler()->handle(['path' => $outside]);
            self::assertFalse($absolute['ok'], 'an absolute path outside the root must be refused');
        } finally {
            unlink($outside);
        }
    }

    /** Without `path` there is nothing to read — answered, not thrown. */
    /**
     * A SECRET HAS ONE PLACE TO LIVE (greenhouse evidence/1161). The files a house keeps a secret in — its
     * environment file, the envelope a declared key is written to, Composer's credentials — are inside the
     * root and real, but a read does not hand them back. Everything else reads as before.
     *
     * @param string $path the relative path of a file that holds a secret
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('secretFiles')]
    public function testAFileThatHoldsASecretIsNotRead(string $path): void
    {
        $full = $this->root . '/' . $path;
        @mkdir(\dirname($full), 0o775, true);
        file_put_contents($full, "SECRET=canary\n");

        $result = $this->handler()->handle(['path' => $path]);

        self::assertFalse($result['ok'], $path);
        self::assertArrayNotHasKey('content', $result);
        self::assertStringNotContainsString('canary', (string) ($result['error'] ?? ''), 'the refusal never carries the value');
    }

    /** @return iterable<string, array{string}> */
    public static function secretFiles(): iterable
    {
        yield 'the environment file' => ['.env'];
        yield 'the secret envelope' => ['.milpa/secrets.json'];
        yield 'composer credentials' => ['auth.json'];
    }

    /**
     * A SECRET-NAMED FILE AT ANY DEPTH, IN ANY CASE, IS A SECRET (greenhouse decisions/0618, F-SECRETNEST). The rule
     * the house keeps is milpa/app-runtime's — a `.env*` basename (bar templates) and `.milpa/secrets.json` /
     * `auth.json`, at any depth, case-insensitively — and a copy of one (a trial, a boot candidate) is a secret too
     * (greenhouse evidence/1161). It is asked about the file each path resolves to, so none of these is handed back.
     *
     * @param string $path the relative path of a secret file (nested, cased, or in a copy)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('secretCopies')]
    public function testACopyOfASecretFileIsNotReadEither(string $path): void
    {
        $full = $this->root . '/' . $path;
        @mkdir(\dirname($full), 0o775, true);
        file_put_contents($full, "SECRET=canary\n");

        $result = $this->handler()->handle(['path' => $path]);

        self::assertFalse($result['ok'], $path);
        self::assertArrayNotHasKey('content', $result);
        self::assertStringNotContainsString('canary', (string) ($result['error'] ?? ''));
    }

    /** @return iterable<string, array{string}> */
    public static function secretCopies(): iterable
    {
        yield 'a trial copy of the envelope' => ['var/trials/w1/copy/.milpa/secrets.json'];
        yield 'a trial copy of composer credentials' => ['var/trials/w1/copy/auth.json'];
        yield 'a trial copy of the environment file' => ['var/trials/w1/copy/.env'];
        yield 'a boot candidate copy of the envelope' => ['var/boot-candidates/b1/.milpa/secrets.json'];
        yield 'an env-local of the house' => ['.env.local'];
        yield 'an env for production' => ['.env.production'];
        yield 'a trial copy of env-local' => ['var/trials/w1/copy/.env.local'];
        // F-SECRETNEST (1): a secret-named file in a SUBDIRECTORY is still a secret.
        yield 'a nested env in config' => ['config/.env'];
        yield 'a nested env-local in src' => ['src/.env.local'];
        yield 'a nested env-production in public' => ['public/.env.production'];
        yield 'a nested auth.json in tools' => ['tools/auth.json'];
        yield 'a nested envelope under .milpa' => ['sub/.milpa/secrets.json'];
        yield 'a deeply nested env' => ['a/b/c/.env'];
        yield 'a nested env inside a copy' => ['var/trials/w1/copy/src/.env'];
        // F-SECRETNEST (2): the name is matched case-insensitively.
        yield 'a capitalized env' => ['.Env.staging'];
        yield 'an uppercase envelope' => ['.MILPA/SECRETS.JSON'];
        yield 'uppercase composer credentials' => ['AUTH.JSON'];
    }

    /**
     * THE PUBLISHED NAME RULE OVER THE CALLER'S PATH IS THE FLOOR (greenhouse decisions/0618, F-STOREDFOLD-N). When
     * the canonical name resolves to a non-secret alias — here a second hardlink `aaa.json` that sorts first, so the
     * stored-name walk picks it — the read is STILL refused, because the published rule is also asked of the path as
     * the caller wrote it (`sub/.milpa/secrets.json`). On a folding disk this is the nested secret stored under a
     * folded name; the leg is the same. Red on round 5 (canonical only), green now.
     */
    public function testASecretIsRefusedByTheCallerSpellingEvenWhenCanonicalResolvesElsewhere(): void
    {
        @mkdir($this->root . '/sub/.milpa', 0o775, true);
        file_put_contents($this->root . '/sub/.milpa/secrets.json', "SECRET=canary\n");
        if (!@link($this->root . '/sub/.milpa/secrets.json', $this->root . '/sub/.milpa/aaa.json')) {
            self::markTestSkipped('the filesystem does not support hardlinks');
        }

        $result = $this->handler()->handle(['path' => 'sub/.milpa/secrets.json']);

        self::assertFalse($result['ok'], 'the caller spelled a secret name; it is refused');
        self::assertStringNotContainsString('canary', (string) ($result['error'] ?? ''));
    }

    /**
     * THE INODE LEG COMPARES DEVICE AND INODE (greenhouse decisions/0618, F-COVER-5 / K3). Pins
     * {@see SecretFiles::identityInSet} directly, so a regression comparing the inode NUMBER alone — a file on
     * another device with a secret's inode number — is caught without a second disk.
     */
    public function testTheInodeLegRequiresTheDeviceToo(): void
    {
        $set = [[41, 7], [42, 9]];
        self::assertTrue(SecretFiles::identityInSet($set, 41, 7));
        self::assertFalse(SecretFiles::identityInSet($set, 99, 7), 'same inode number, different device, is not a match');
    }

    /**
     * A SECRET IS WITHHELD BY WHAT THE FILE IS, NOT ITS NAME (greenhouse decisions/0618, F1-R). A hardlink to the
     * real envelope, under an innocent `.php` name in `src/`, shares the envelope's inode and is refused — the
     * operator-hardlink that the name-based rule used to hand over.
     */
    public function testAHardlinkToASecretIsRefusedByIdentity(): void
    {
        @mkdir($this->root . '/.milpa', 0o775, true);
        file_put_contents($this->root . '/.milpa/secrets.json', "SECRET=canary\n");
        @mkdir($this->root . '/src', 0o775, true);
        if (!@link($this->root . '/.milpa/secrets.json', $this->root . '/src/innocent.php')) {
            self::markTestSkipped('the filesystem does not support hardlinks');
        }

        $result = $this->handler()->handle(['path' => 'src/innocent.php']);

        self::assertFalse($result['ok'], 'a hardlink to the envelope is the envelope');
        self::assertStringNotContainsString('canary', (string) ($result['error'] ?? ''));
    }

    /**
     * THE SECRET RULE READS THE STORED NAME, NOT THE CALLER'S SPELLING (greenhouse decisions/0618, mutant N1). A
     * second hardlink to a `.env` in the same directory, under an innocent name, resolves by identity to the stored
     * `.env` — so the read is refused even on a case-sensitive disk, which pins that `holds()` asks the name rule of
     * the canonical path, not the spelling.
     */
    public function testASecretReadThroughAnInnocentHardlinkNameIsRefused(): void
    {
        @mkdir($this->root . '/pkg', 0o775, true);
        file_put_contents($this->root . '/pkg/.env', "SECRET=canary\n");
        if (!@link($this->root . '/pkg/.env', $this->root . '/pkg/plain.txt')) {
            self::markTestSkipped('the filesystem does not support hardlinks');
        }

        $result = $this->handler()->handle(['path' => 'pkg/plain.txt']);

        self::assertFalse($result['ok'], 'plain.txt is a second name for pkg/.env');
        self::assertStringNotContainsString('canary', (string) ($result['error'] ?? ''));
    }

    /**
     * THE RULE NEVER NARROWS WHAT THE PUBLISHED RULE REFUSED (greenhouse decisions/0618, F-SECRETNEST). Identity may
     * only ADD refusals. Over a tree of secret-named files at several depths, cases, and inside a copy, the set
     * `holds()` refuses must contain everything the published milpa/devtools 0.44.2 name rule refused — the oracle
     * copied verbatim below. (Red on the round-3 identity rewrite, which narrowed to top-level/exact-case only.)
     */
    public function testHoldsRefusesEverythingThePublishedRuleRefused(): void
    {
        $tree = [
            '.env', '.env.local', '.env.production', 'auth.json', '.milpa/secrets.json',
            'config/.env', 'src/.env.local', 'public/.env.production', 'tools/auth.json', 'sub/.milpa/secrets.json',
            'a/b/c/.env', '.Env.staging', 'config/.ENV', 'TOOLS/AUTH.JSON',
            'var/trials/w1/copy/.env', 'var/trials/w1/copy/src/.env.local', 'var/trials/w1/copy/.milpa/secrets.json',
            // not secrets by the published rule — must stay readable, so the new rule must not over-refuse either:
            '.env.example', '.env.dist', 'docs/secrets.json', 'src/auth.json.php', 'config/myenv', 'src/App.php',
        ];
        foreach ($tree as $rel) {
            $full = $this->root . '/' . $rel;
            @mkdir(\dirname($full), 0o775, true);
            file_put_contents($full, "SECRET=canary\n");
        }
        foreach ($tree as $rel) {
            $old = self::publishedIsSecret($rel);
            $new = SecretFiles::holds((string) realpath($this->root . '/' . $rel), $this->root);
            if ($old) {
                self::assertTrue($new, "head must still refuse what 0.44.2 refused: {$rel}");
            } else {
                self::assertFalse($new, "head must not over-refuse what 0.44.2 read: {$rel}");
            }
        }
    }

    /** The published milpa/devtools 0.44.2 SecretFiles::isSecret, verbatim, as the set-difference oracle. */
    private static function publishedIsSecret(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        $base = strtolower(basename($relative));
        if (str_starts_with($base, '.env') && ! \in_array($base, ['.env.example', '.env.dist', '.env.template', '.env.sample'], true)) {
            return true;
        }
        $low = strtolower($relative);
        foreach (['.milpa/secrets.json', 'auth.json'] as $tail) {
            if ($low === $tail || str_ends_with($low, '/' . $tail)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $path a file that only resembles a secret file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lookAlikes')]
    public function testAFileThatOnlyLooksLikeASecretIsReadAsAlways(string $path): void
    {
        $full = $this->root . '/' . $path;
        @mkdir(\dirname($full), 0o775, true);
        file_put_contents($full, "not a secret\n");

        self::assertTrue($this->handler()->handle(['path' => $path])['ok'], $path);
    }

    /** @return iterable<string, array{string}> */
    public static function lookAlikes(): iterable
    {
        yield 'a secrets.json outside .milpa' => ['docs/secrets.json'];
        yield 'an auth.json with a suffix' => ['src/auth.json.php'];
        yield 'an env example' => ['.env.example'];
        yield 'a file ending in env' => ['config/myenv'];
        yield 'an env example template' => ['.env.example'];
        yield 'an env dist template' => ['.env.dist'];
        yield 'an env template' => ['.env.template'];
    }

    public function testASourceFileNamedLikeNoSecretStillReads(): void
    {
        file_put_contents($this->root . '/src/secrets.php', "<?php // not the envelope\n");

        $result = $this->handler()->handle(['path' => 'src/secrets.php']);

        self::assertTrue($result['ok'], 'only the house\'s own secret files are withheld, by their exact path');
    }

    /**
     * SOURCE:READ READS SOURCE, NOT THE RUNNING HOUSE'S STATE (greenhouse decisions/0618). `var/` and
     * `storage/` are what the house writes as it runs — the passkey session store (a live cookie), the HTTP
     * confirm tokens, the enrollment ledger, the agent's session log, trial copies. A read of source is not
     * the door to any of it, even for a file that is not itself a named secret.
     *
     * @param string $path a non-secret file under the house's runtime state
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('houseState')]
    public function testTheRunningHousesStateIsNotReadAsSource(string $path): void
    {
        $full = $this->root . '/' . $path;
        @mkdir(\dirname($full), 0o775, true);
        file_put_contents($full, "{\"canary\":\"value\"}\n");

        $result = $this->handler()->handle(['path' => $path]);

        self::assertFalse($result['ok'], $path);
        self::assertArrayNotHasKey('content', $result);
        self::assertStringNotContainsString('canary', (string) ($result['error'] ?? ''), 'the refusal never carries the value');
    }

    /** @return iterable<string, array{string}> */
    public static function houseState(): iterable
    {
        yield 'the passkey session store' => ['var/passkey/sessions.json'];
        yield 'the HTTP confirm tokens' => ['storage/confirm-tokens.json'];
        yield 'the enrollment ledger' => ['storage/identity/enrollments.json'];
        yield 'the token store' => ['storage/tokens.json'];
        yield 'the agent session log' => ['var/agent-sessions.jsonl'];
        yield 'an invitation ledger' => ['storage/identity/invitations.json'];
        // D20: a copy's OWN state is still state — refused, judged relative to the copy's root.
        yield 'a trial copy own session store' => ['var/trials/w1/copy/var/passkey/sessions.json'];
        yield 'a trial copy own confirm tokens' => ['var/trials/w1/copy/storage/confirm-tokens.json'];
        yield 'a boot candidate own var' => ['var/boot-candidates/b1/var/passkey/sessions.json'];
        // A copy nested in a copy reads as the outer copy's var state — refused.
        yield 'a copy inside a copy' => ['var/trials/w1/copy/var/trials/w2/copy/src/Deep.php'];
    }

    /**
     * A COPY OF THE HOUSE IS READ LIKE A HOUSE: ITS SOURCE YES, ITS STATE NO (greenhouse decisions/0618, D20).
     *
     * The agent reads the source of its own trial copy to work on it — the one house-state read it actually makes
     * (greenhouse evidence/1199). A trial, an exercise and a boot candidate are copies: their `src/`, `tests/`,
     * `config/` are source and read; their own `var/`/`storage/` is state and refused (covered above).
     *
     * @param string $path a source file inside a copy of the house
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('copySource')]
    public function testACopyOfTheHouseIsReadLikeAHouse(string $path): void
    {
        $full = $this->root . '/' . $path;
        @mkdir(\dirname($full), 0o775, true);
        file_put_contents($full, "<?php // copy source\n");

        self::assertTrue($this->handler()->handle(['path' => $path])['ok'], $path);
    }

    /** @return iterable<string, array{string}> */
    public static function copySource(): iterable
    {
        yield 'a trial copy src' => ['var/trials/w1/copy/src/Sample.php'];
        yield 'a trial copy test' => ['var/trials/w1/copy/tests/SampleTest.php'];
        yield 'a trial copy config' => ['var/trials/w1/copy/config/app.php'];
        yield 'an exercise copy src' => ['var/exercises/e1/copy/src/Sample.php'];
        yield 'a boot candidate src' => ['var/boot-candidates/b1/src/Sample.php'];
    }

    public function testADirectoryThatMerelyLooksLikeStateStillReads(): void
    {
        // Only `var/` and `storage/` at the root are the house's state; a source file whose name contains
        // "var" or "storage" elsewhere is read as always.
        foreach (['src/VarExporter.php', 'src/Storage/Driver.php', 'docs/storage.md'] as $path) {
            $full = $this->root . '/' . $path;
            @mkdir(\dirname($full), 0o775, true);
            file_put_contents($full, "source\n");
            self::assertTrue($this->handler()->handle(['path' => $path])['ok'], $path);
        }
    }

    public function testAMissingPathInputIsAnswered(): void
    {
        $result = $this->handler()->handle([]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('path', (string) $result['error']);
    }

    /** A file that is not there is `ok:false` with a reason, never an exception. */
    public function testAMissingFileIsOkFalseNotAnException(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Nope.php']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('src/Nope.php', (string) $result['error']);
    }

    /** A directory is not a file to read. */
    public function testADirectoryIsNotAFileToRead(): void
    {
        $result = $this->handler()->handle(['path' => 'src']);

        self::assertFalse($result['ok']);
    }

    /** Asking past the end is answered with the file's real size, so the next ask can be right. */
    public function testFromPastTheEndIsAnsweredWithTheFileSize(): void
    {
        $result = $this->handler()->handle(['path' => 'src/Sample.php', 'from' => 99]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('10 lines', (string) $result['error']);
    }

    /** The per-call budget is clamped to the ceiling — a bigger ask is a second `from`, not a dump. */
    public function testTheLineBudgetIsClamped(): void
    {
        $big = '';
        for ($number = 1; $number <= 450; ++$number) {
            $big .= "row {$number}\n";
        }
        file_put_contents($this->root . '/src/Big.php', $big);

        $result = $this->handler()->handle(['path' => 'src/Big.php', 'lines' => 9999]);

        self::assertTrue($result['ok']);
        self::assertSame(400, $result['lines']);
        self::assertSame(450, $result['total_lines']);
        self::assertTrue($result['truncated']);

        $floor = $this->handler()->handle(['path' => 'src/Big.php', 'lines' => 0]);
        self::assertTrue($floor['ok']);
        self::assertSame(1, $floor['lines'], 'the floor is one line, not zero');
    }

    /** An empty file reads as its honest content: nothing. */
    public function testAnEmptyFileReadsAsEmpty(): void
    {
        file_put_contents($this->root . '/src/Empty.php', '');

        $result = $this->handler()->handle(['path' => 'src/Empty.php']);

        self::assertTrue($result['ok']);
        self::assertSame('', $result['content']);
        self::assertSame(0, $result['total_lines']);
        self::assertSame(0, $result['lines']);
        self::assertFalse($result['truncated']);
    }

    private function handler(): SourceReadHandler
    {
        return new SourceReadHandler(new RootResolver($this->root));
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
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
