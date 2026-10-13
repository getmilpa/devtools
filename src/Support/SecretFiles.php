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

namespace Milpa\DevTools\Support;

/**
 * THE FILES OF A HOUSE THAT HOLD SECRETS — a secret has one place to live.
 *
 * A reading operation of this package returns a slice of a file inside the app root; the files where the house
 * keeps a secret — its environment file and the family beside it (`.env`, `.env.local`, `.env.production`…), the
 * envelope a declared key is written to, and Composer's credentials — are not handed back, nor a copy a trial or
 * a boot candidate kept.
 *
 * The rule that NAMES those files is milpa/app-runtime's: a `.env*` basename (bar the committed templates) at any
 * depth, and `.milpa/secrets.json` / `auth.json` at any depth, compared case-insensitively — this package keeps
 * the same rule so the two never disagree on WHICH files are secret. {@see holds()} asks that rule about the file
 * each path resolves to, and ADDS a device+inode check so an operator hardlink to a secret under an innocent name
 * is caught too. It never NARROWS the rule: a directory that cannot be listed fails CLOSED (the file is withheld).
 */
final class SecretFiles
{
    /** The envelope and Composer's credentials, matched by the tail of a path. */
    private const TAIL = ['.milpa/secrets.json', 'auth.json'];

    /** `.env*` names that are placeholders, not secrets. */
    private const TEMPLATES = ['.env.example', '.env.dist', '.env.template', '.env.sample'];

    /** The copy roots a secret file may also sit under — kept in step with {@see SourcePath} (greenhouse decisions/0618). */
    private const COPY_GLOBS = ['var/trials/*/copy', 'var/exercises/*/copy', 'var/boot-candidates/*'];

    /**
     * Whether a path from the root of a house NAMES a file the house keeps a secret in — the `.env` family (bar
     * the templates) and the envelope / Composer credentials, at ANY depth, case-insensitively. This is the rule
     * milpa/app-runtime keeps; {@see holds()} asks it about the file a path resolves to.
     */
    public static function isSecret(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        $base = strtolower(basename($relative));
        if (str_starts_with($base, '.env') && ! \in_array($base, self::TEMPLATES, true)) {
            return true;
        }
        $low = strtolower($relative);
        foreach (self::TAIL as $tail) {
            if ($low === $tail || str_ends_with($low, '/' . $tail)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a file already admitted by {@see SourcePath::inside()} is one the house keeps a secret in. The union
     * of three legs, so none NARROWS the published rule (greenhouse decisions/0618):
     *   - the published name rule over the caller's path exactly — the FLOOR: whatever 0.44.2 refused by name, at
     *     any depth, is refused;
     *   - the same name rule over the file each path resolves to (its stored-name path), so two spellings of one
     *     file get one answer;
     *   - the file's device+inode against the house's real root/copy-root secret files — adds a hardlink under an
     *     innocent name, and a root/copy-root secret the name rule does not otherwise recognise.
     * A path whose directory cannot be listed is withheld (fail closed).
     */
    public static function holds(string $file, string $root): bool
    {
        $canonical = SourcePath::canonical($file, $root);
        if ($canonical === null) {
            return true;
        }

        return self::isSecret(SourcePath::relative($file, $root))
            || self::isSecret($canonical)
            || self::isOneOfTheSecretFiles($file, $root);
    }

    /**
     * Whether $file IS one of the house's real secret files, by device + inode. This is what identity ADDS over the
     * name rule: an alias (a hardlink under an innocent name) and a root/copy-root secret the name rule does not
     * otherwise recognise both resolve to the same inode as the real file. The named secrets are FEW — the
     * environment family and the two tails at the house root and each copy root — so the set is small and not gated
     * on link count.
     */
    private static function isOneOfTheSecretFiles(string $file, string $root): bool
    {
        $st = @stat($file);
        if ($st === false) {
            return false;
        }

        return self::identityInSet(self::secretInodes($root), (int) $st['dev'], (int) $st['ino']);
    }

    /**
     * Whether ($dev, $ino) is in the set — both numbers compared, so a file on another device with the same inode
     * NUMBER as a secret is not mistaken for it. The one place that comparison lives, so a test pins it.
     *
     * @internal
     *
     * @param list<array{0: int, 1: int}> $inodes
     */
    public static function identityInSet(array $inodes, int $dev, int $ino): bool
    {
        foreach ($inodes as [$edev, $eino]) {
            if ($edev === $dev && $eino === $ino) {
                return true;
            }
        }

        return false;
    }

    /**
     * The device + inode of every real secret file the house keeps at its root and in each copy. The environment
     * family is found by listing each base; the two fixed names are looked up by path, so the entry the filesystem
     * resolves is the one compared.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function secretInodes(string $root): array
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return [];
        }
        $bases = [$rootReal];
        foreach (self::COPY_GLOBS as $glob) {
            foreach (glob($rootReal . '/' . $glob, \GLOB_ONLYDIR) ?: [] as $copy) {
                $bases[] = $copy;
            }
        }
        $inodes = [];
        foreach ($bases as $base) {
            foreach (scandir($base) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && self::isSecret($entry)) {
                    self::addInode($inodes, $base . '/' . $entry);
                }
            }
            foreach (self::TAIL as $tail) {
                self::addInode($inodes, $base . '/' . $tail);
            }
        }

        return $inodes;
    }

    /**
     * Add the device + inode of a file that exists to the set.
     *
     * @param list<array{0: int, 1: int}> $inodes
     */
    private static function addInode(array &$inodes, string $path): void
    {
        $st = @stat($path);
        if ($st !== false) {
            $inodes[] = [$st['dev'], $st['ino']];
        }
    }
}
