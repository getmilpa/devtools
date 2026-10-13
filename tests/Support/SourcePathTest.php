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

namespace Milpa\DevTools\Tests\Support;

use Milpa\DevTools\Support\SourcePath;
use PHPUnit\Framework\TestCase;

/**
 * ONE RULE, FOR BOTH READERS: WHICH HOUSE IS THIS PATH FROM, AND IS IT SOURCE OR STATE? (greenhouse decisions/0618.)
 *
 * `source:read`/`source:page` refuse a house's STATE and read its source; `test` runs only a house's TESTS. A path
 * inside a COPY of the house (a trial, an exercise, a boot candidate) is judged relative to that copy — its source is
 * source, its own `var/`/`storage/` is state. The rule reads the file each path RESOLVES to (its stored-name path by
 * device + inode), so a folded spelling gets one answer; a path whose directory cannot be listed fails CLOSED. The
 * cases plant real files, because the identity rule asks the filesystem what each path is.
 */
final class SourcePathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-sp-' . bin2hex(random_bytes(5));
        @mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    private function plant(string $relative, bool $dir = false): string
    {
        $full = $this->root . '/' . $relative;
        @mkdir($dir ? $full : \dirname($full), 0o775, true);
        if (! $dir && ! file_exists($full)) {
            file_put_contents($full, "x\n");
        }

        return $full;
    }

    /**
     * @param bool $expected whether the resolved path is the running house's STATE (D20)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('houseStateCases')]
    public function testIsHouseState(string $path, bool $expected): void
    {
        $this->plant($path);
        self::assertSame($expected, SourcePath::isHouseState($this->root . '/' . $path, $this->root), $path);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function houseStateCases(): iterable
    {
        yield 'the session store'                  => ['var/passkey/sessions.json', true];
        yield 'the confirm tokens'                 => ['storage/confirm-tokens.json', true];
        yield 'a log under var'                    => ['var/coa-tui.log', true];
        yield 'the real house source'              => ['src/Http/IdentityChain.php', false];
        yield 'the real house config'              => ['config/app.php', false];
        yield 'the real house tests'               => ['tests/SomeTest.php', false];
        // D20: a trial copy is a house of its own — its source is source, its own state is state.
        yield 'a trial copy source'                => ['var/trials/w1/copy/src/Sample.php', false];
        yield 'a trial copy test'                  => ['var/trials/w1/copy/tests/SampleTest.php', false];
        yield 'a trial copy own var'               => ['var/trials/w1/copy/var/passkey/sessions.json', true];
        yield 'a trial copy own storage'           => ['var/trials/w1/copy/storage/confirm-tokens.json', true];
        yield 'an exercise copy source'            => ['var/exercises/e1/copy/src/Sample.php', false];
        yield 'a boot candidate source'            => ['var/boot-candidates/b1/src/Sample.php', false];
        yield 'a boot candidate own var'           => ['var/boot-candidates/b1/var/passkey/sessions.json', true];
        // A copy nested inside a copy strips only the outer root -> reads as the outer copy's var state (refused).
        yield 'a copy inside a copy'               => ['var/trials/w1/copy/var/trials/w2/copy/src/Deep.php', true];
        // A directory merely NAMED copy, not a trial copy, is ordinary source.
        yield 'a source dir named copy'            => ['src/copy/Real.php', false];
        yield 'a var dir named copy (not a trial)' => ['var/copy/thing.json', true];
    }

    /**
     * File-path cases of «is this a test?» — a file must resolve to a `.php` under a `tests/` of its house or a copy.
     *
     * @param bool $expected whether the resolved path is a TEST of the house it belongs to (D22)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fileCases')]
    public function testIsTestForFiles(string $path, bool $expected): void
    {
        $this->plant($path);
        self::assertSame($expected, SourcePath::isTest($this->root . '/' . $path, $this->root), $path);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function fileCases(): iterable
    {
        yield 'a house test file'        => ['tests/SampleTest.php', true];
        yield 'a trial copy test'        => ['var/trials/w1/copy/tests/SampleTest.php', true];
        // F2: a non-`.php` file left under tests/ is NOT a test — it must not be handed to PHPUnit to include.
        yield 'a non-php file under tests' => ['tests/notphp.txt', false];
        // F2-MUT: a name that CONTAINS but does not END in .php is not a test (pins str_ends_with vs str_contains).
        yield 'a .php.bak backup under tests' => ['tests/SampleTest.php.bak', false];
        yield 'a .phpx file under tests'      => ['tests/Weird.phpx', false];
        yield 'the env file'             => ['.env', false];
        yield 'the envelope'             => ['.milpa/secrets.json', false];
        yield 'the session store'        => ['var/passkey/sessions.json', false];
        yield 'the confirm tokens'       => ['storage/confirm-tokens.json', false];
        yield 'ordinary source'          => ['src/Http/IdentityChain.php', false];
        yield 'a trial copy source'      => ['var/trials/w1/copy/src/Sample.php', false];
        yield 'a dir named tests in src' => ['src/tests/Helper.php', false];
    }

    /**
     * A DIRECTORY under `tests/` is a test target (PHPUnit discovers its `*.php` tests and ignores the rest), so it
     * runs — the house's, and a copy's.
     */
    public function testATestDirectoryRuns(): void
    {
        $this->plant('tests/Plugins/Hello', dir: true);
        $this->plant('var/trials/w1/copy/tests/Plugins/Hello', dir: true);
        $this->plant('tests/Plugins/Hello/leftover.txt');

        self::assertTrue(SourcePath::isTest($this->root . '/tests', $this->root), 'the house tests/ dir');
        self::assertTrue(SourcePath::isTest($this->root . '/tests/Plugins/Hello', $this->root), 'a plugin test dir');
        self::assertTrue(SourcePath::isTest($this->root . '/var/trials/w1/copy/tests/Plugins/Hello', $this->root), 'a copy test dir');
        self::assertFalse(SourcePath::isTest($this->root . '/tests/Plugins/Hello/leftover.txt', $this->root), 'a non-php file is not a test');
    }

    /**
     * THE CANONICAL PATH IS THE STORED NAME, NOT THE SPELLING (greenhouse decisions/0618). Two hardlinks of one
     * inode sit side by side; the directory holds both names but the file IS one entry. `canonical` returns the
     * name the directory actually lists for that inode — pinning that the stored-name walk runs (MUT1) and that it
     * does not fall back to the caller's basename (MUT3), on a plain case-sensitive disk.
     */
    public function testCanonicalReturnsTheStoredNameOfAHardlinkedPair(): void
    {
        $this->plant('pkg', dir: true);
        $first = $this->root . '/pkg/a-first.php';
        $second = $this->root . '/pkg/z-second.php';
        file_put_contents($first, "<?php\n");
        if (! @link($first, $second)) {
            self::markTestSkipped('the filesystem does not support hardlinks');
        }
        // scandir sorts, so 'a-first.php' is the stored name the inode is found under; asking by 'z-second.php'
        // must still canonicalise to 'pkg/a-first.php' (the first listed entry of that inode).
        self::assertSame('pkg/a-first.php', SourcePath::canonical($second, $this->root), 'the stored name, not the spelling');
    }

    /**
     * BOTH DEVICE AND INODE DECIDE WHICH ENTRY A FILE IS (greenhouse decisions/0618). Pins {@see SourcePath::matchByIdentity}
     * directly, so a regression that compares the inode alone (ignoring the device) is caught without a second disk:
     * a decoy sharing the inode but on another device must NOT win. A LIST of `[name, dev, ino]`, so an entry whose
     * name is a decimal integer stays a string (F-NUM).
     */
    public function testMatchByIdentityRequiresTheDeviceToo(): void
    {
        $entries = [
            ['decoy-same-inode-other-device', 41, 7],
            ['the-file', 42, 7],
            ['unrelated', 42, 9],
            ['2024', 42, 11],
        ];
        self::assertSame('the-file', SourcePath::matchByIdentity($entries, 42, 7));
        self::assertNull(SourcePath::matchByIdentity($entries, 99, 7), 'no entry on device 99');
        self::assertSame('2024', SourcePath::matchByIdentity($entries, 42, 11), 'a decimal-integer name stays a string');
    }

    /**
     * AN INTEGER-NAMED DIRECTORY MUST NOT CRASH THE WALK (greenhouse decisions/0618, F-NUM). A decimal name
     * (`2024`, `0`, `007`) would become an integer array key in PHP; the walk uses a list, so these read normally.
     *
     * @param string $path a path under a directory whose name is numeric-looking
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('numericDirCases')]
    public function testANumericDirectoryNameDoesNotCrashTheWalk(string $path, bool $state): void
    {
        $this->plant($path);
        // The point is that no TypeError is thrown; the classification is sane too.
        self::assertSame($state, SourcePath::isHouseState($this->root . '/' . $path, $this->root), $path);
        self::assertIsString(SourcePath::canonical($this->root . '/' . $path, $this->root), $path);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function numericDirCases(): iterable
    {
        yield 'a year folder in src'      => ['src/2024/Note.php', false];
        yield 'a numeric id under tests'  => ['tests/2025/SomeTest.php', false];
        yield 'a zero-named dir'          => ['src/0/Zero.php', false];
        yield 'an octal-looking dir'      => ['src/007/Bond.php', false];
        yield 'a float-looking dir'       => ['src/1e3/Sci.php', false];
        yield 'a negative-looking dir'    => ['src/-1/Neg.php', false];
        yield 'a numeric dir under var'   => ['var/2024/cache.json', true];
    }

    /**
     * THE STATE RULE MATCHES THE SEGMENT CASE-INSENSITIVELY, LIKE THE SECRET RULE (greenhouse decisions/0618,
     * F-STOREDFOLD (b)). A stored `Storage/` or `Var/` is the house's state by any case — the same comparison all
     * three questions use, so none is a case asymmetry.
     */
    public function testTheStateRuleIsCaseInsensitiveOnTheStoredSegment(): void
    {
        $this->plant('Storage/confirm-tokens.json');
        $this->plant('VAR/passkey/sessions.json');
        if (realpath($this->root . '/Storage') === realpath($this->root . '/storage')) {
            // a folding disk already made them the real dir; the point still holds, assert it anyway.
        }
        self::assertTrue(SourcePath::isHouseState($this->root . '/Storage/confirm-tokens.json', $this->root), 'Storage/ is state');
        self::assertTrue(SourcePath::isHouseState($this->root . '/VAR/passkey/sessions.json', $this->root), 'VAR/ is state');
    }

    /**
     * THE TEST SEGMENT IS MATCHED CASE-INSENSITIVELY (greenhouse decisions/0618, F-COVER-5 / K6), the same comparison
     * the secret and state rules use. A `.php` file under a stored `Tests/` runs; a regression that compared the
     * segment case-sensitively would refuse a real test (loud).
     */
    public function testTheTestSegmentIsCaseInsensitive(): void
    {
        $this->plant('Tests/SampleTest.php');
        if (realpath($this->root . '/Tests') === realpath($this->root . '/tests')) {
            // a folding disk already made them one dir; the assertion still holds.
        }
        self::assertTrue(SourcePath::isTest($this->root . '/Tests/SampleTest.php', $this->root), 'a .php under Tests/ is a test');
    }

    /**
     * A PATH WHOSE DIRECTORY CANNOT BE LISTED FAILS CLOSED (greenhouse decisions/0618, F-SECRETNEST (3)). An
     * operator mode that allows traverse but not list (`0311`) leaves the file openable yet its identity
     * unverifiable; the rules then withhold — state, not source; not a test.
     */
    public function testAnUnlistableDirectoryIsTreatedAsState(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permission bits');
        }
        $this->plant('sealed/app.php');
        $this->plant('sealed/tests', dir: true);
        $this->plant('sealed/tests/SomeTest.php');
        @chmod($this->root . '/sealed', 0o311); // --x: traverse, not list
        try {
            if (@scandir($this->root . '/sealed') !== false) {
                self::markTestSkipped('this environment can still list a 0311 directory');
            }
            self::assertTrue(SourcePath::isHouseState($this->root . '/sealed/app.php', $this->root), 'unlistable -> state');
            self::assertFalse(SourcePath::isTest($this->root . '/sealed/tests/SomeTest.php', $this->root), 'unlistable -> not a test');
            self::assertNull(SourcePath::canonical($this->root . '/sealed/app.php', $this->root), 'unlistable -> no canonical path');
        } finally {
            @chmod($this->root . '/sealed', 0o775);
        }
    }

    private static function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            @chmod($f->getPathname(), 0o775);
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($root);
    }
}
