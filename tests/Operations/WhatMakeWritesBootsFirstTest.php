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

use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Support\RootResolver;
use Milpa\Plugin\Contracts\BootWitnessInterface;
use PHPUnit\Framework\TestCase;

/**
 * What `make` writes — its merge into a plugin that already exists included — boots first (greenhouse decisions/0527).
 *
 * The witness is the host's (milpa/app-runtime boots a copy of the house; its own tests and evidence/1061 do
 * that for real). What this package owes it is exact: every byte the run would write, house-relative, before
 * anything is written; nothing written when it refuses, and the refusal as the answer.
 *
 * @guards the witness sees the merged plugin file and the new files, house-relative, before any write; a refusal
 *         writes nothing — the declared plugin keeps its bytes and inode, no new file appears — and answers
 *         `ok: false` with `unwritten`, `house_boots`, `reason` and every file marked `unwritten`; a put-back marks
 *         them `rolled-back`; a write it lets through lands and says `house_boots`; a dry run asks nothing; with
 *         no witness the run writes as it always did (the positive control)
 *
 * @refuses a make run whose files the house cannot boot with
 *
 * @subject-in milpa/devtools
 */
final class WhatMakeWritesBootsFirstTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-make-witness-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/config/plugins.php', "<?php return [App\\Plugins\\Blog\\Blog::class];\n");
        $made = (new MakeHandler(new RootResolver($this->root), static fn (): ?BootWitnessInterface => null))
            ->handle(['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog', 'no_verify' => true]);
        self::assertTrue($made['ok']);
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function plugin(): string
    {
        return $this->root . '/src/Plugins/Blog/Blog.php';
    }

    private function make(?WitnessThatRecords $witness): MakeHandler
    {
        return new MakeHandler(new RootResolver($this->root), static fn (string $root): ?BootWitnessInterface => $witness);
    }

    public function testAMergeTheHouseCannotBootWithIsNeverWritten(): void
    {
        $witness = new WitnessThatRecords(['refused' => 'The house does not boot with this change: Error: Class "Milpa\Data\Repository" not found. Nothing was written; the house boots as it is.', 'said' => [
            'unwritten' => ['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/Services/Posts.php'],
            'house_boots' => true,
            'reason' => 'Error: Class "Milpa\Data\Repository" not found',
        ]]);
        $bytes = (string) file_get_contents($this->plugin());
        $inode = fileinode($this->plugin());

        $r = $this->make($witness)->handle(['what' => 'service', 'plugin' => 'Blog', 'name' => 'Posts']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('does not boot with this change', (string) $r['error']);
        self::assertTrue($r['house_boots']);
        self::assertSame(['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/Services/Posts.php'], $r['unwritten']);
        self::assertSame(['unwritten', 'unwritten'], array_column($r['files'], 'action'));
        self::assertNull($r['verify'], 'nothing was written, so nothing is verified');
        self::assertSame($bytes, file_get_contents($this->plugin()), 'the declared plugin keeps its bytes');
        self::assertSame($inode, fileinode($this->plugin()), 'and its inode');
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Blog/Services/Posts.php');
        self::assertFalse($witness->committed, 'the write itself never ran');
    }

    public function testTheWitnessIsShownEveryByteHouseRelativeBeforeAnyWrite(): void
    {
        $witness = new WitnessThatRecords(['refused' => 'no', 'said' => []]);

        $this->make($witness)->handle(['what' => 'service', 'plugin' => 'Blog', 'name' => 'Posts']);

        self::assertSame(['src/Plugins/Blog/Services/Posts.php', 'src/Plugins/Blog/Blog.php'], array_keys($witness->writes));
        self::assertStringContainsString('registerService(', $witness->writes['src/Plugins/Blog/Blog.php'], 'the MERGED plugin, not the one on disk');
        self::assertStringNotContainsString('registerService(', (string) file_get_contents($this->plugin()));
        self::assertFalse($witness->recovery, 'make is not a way back');
    }

    public function testAWriteTheHouseBootsWithLandsAndSaysSo(): void
    {
        $witness = new WitnessThatRecords(null);

        $r = $this->make($witness)->handle(['what' => 'service', 'plugin' => 'Blog', 'name' => 'Posts', 'no_verify' => true]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['house_boots']);
        self::assertTrue($witness->committed);
        self::assertSame(['created', 'merged'], array_column($r['files'], 'action'));
        self::assertStringContainsString('registerService(', (string) file_get_contents($this->plugin()));
    }

    public function testAWriteTheLiveHouseRefusedAfterIsMarkedRolledBack(): void
    {
        $witness = new WitnessThatRecords(['refused' => 'The house did not boot after this change was written: x. It was put back to what it held.', 'said' => [
            'rolled_back' => ['src/Plugins/Blog/Blog.php', 'src/Plugins/Blog/Services/Posts.php'],
            'house_boots' => true,
            'reason' => 'x',
        ]]);

        $r = $this->make($witness)->handle(['what' => 'service', 'plugin' => 'Blog', 'name' => 'Posts']);

        self::assertFalse($r['ok']);
        self::assertSame(['rolled-back', 'rolled-back'], array_column($r['files'], 'action'));
        self::assertArrayHasKey('rolled_back', $r);
    }

    public function testADryRunAsksNothing(): void
    {
        $witness = new WitnessThatRecords(['refused' => 'never asked', 'said' => []]);

        $r = $this->make($witness)->handle(['what' => 'service', 'plugin' => 'Blog', 'name' => 'Posts', 'dry_run' => true]);

        self::assertTrue($r['ok']);
        self::assertSame([], $witness->writes);
    }

    public function testWithoutAWitnessTheSameRunWritesAsItAlwaysDid(): void
    {
        // The positive control: the refusal above is the witness's, not a change in what make writes.
        $r = $this->make(null)->handle(['what' => 'service', 'plugin' => 'Blog', 'name' => 'Posts', 'no_verify' => true]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertArrayNotHasKey('house_boots', $r, 'nothing claims it booted');
        self::assertFileExists($this->root . '/src/Plugins/Blog/Services/Posts.php');
    }

    public function testByDefaultItAsksMilpaPluginForTheHostsWitness(): void
    {
        // milpa/devtools has no host here, so the host's witness is not found and the run writes; the lookup is
        // milpa/plugin's (PluginManagementPlugin::hostWitness), the same one plugins.register uses.
        $r = (new MakeHandler(new RootResolver($this->root)))->handle(['what' => 'service', 'plugin' => 'Blog', 'name' => 'Posts', 'no_verify' => true]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertArrayNotHasKey('house_boots', $r);
    }
}

/** A witness that records what it was shown and answers as told — null lets the write through and says `house_boots`. */
final class WitnessThatRecords implements BootWitnessInterface
{
    /** @var array<string, string> */
    public array $writes = [];

    public bool $committed = false;

    public bool $recovery = false;

    /** @param null|array{refused: ?string, said: array<string, mixed>} $answer */
    public function __construct(private readonly ?array $answer)
    {
    }

    public function writeIfItBoots(array $writes, callable $commit, bool $recovery = false, array $deletes = []): array
    {
        $this->writes = $writes;
        $this->recovery = $recovery;
        if ($this->answer !== null) {
            return $this->answer;
        }
        $commit();
        $this->committed = true;

        return ['refused' => null, 'said' => ['house_boots' => true]];
    }
}
