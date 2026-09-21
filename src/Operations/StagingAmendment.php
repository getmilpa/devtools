<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Operations;

/** Repair the staging sibling owned by ImplementHandler; never judge or publish live PHP. */
final class StagingAmendment
{
    /**
     * Check the amendment's bounded input before opening any file.
     *
     * @param array<string, mixed> $input
     */
    public static function validate(array $input): ?string
    {
        if (array_key_exists('content', $input)) {
            return 'mode=amend takes no `content`; supply exact `edits` and the current staging `expected_sha256`';
        }
        if (!is_string($input['expected_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $input['expected_sha256']) !== 1) {
            return 'mode=amend requires `expected_sha256`: the current staging SHA-256 as 64 lowercase hex characters';
        }
        $edits = $input['edits'] ?? null;
        if (!is_array($edits) || !array_is_list($edits) || $edits === []) {
            return 'mode=amend requires a nonempty list of exact {find, replace} pairs in `edits`';
        }
        $bytes = 0;
        foreach ($edits as $edit) {
            if (!is_array($edit) || !is_string($edit['find'] ?? null) || $edit['find'] === ''
                || !is_string($edit['replace'] ?? null)) {
                return 'each amendment requires a nonempty string `find` and a string `replace` (empty means deletion)';
            }
            $bytes += strlen($edit['find']) + strlen($edit['replace']);
            if ($bytes > ImplementHandler::MAX_INLINE_BYTES) {
                return 'refused: total find + replace bytes exceed MAX_INLINE_BYTES (' . ImplementHandler::MAX_INLINE_BYTES
                    . '); split the amendment and use each resulting staging hash';
            }
        }
        return null;
    }

    /**
     * Amend only a confined, existing staging file with the expected current bytes.
     *
     * The lock coordinates amend calls on the opened inode. Recheck that inode and its bytes
     * before atomic replacement. This is not a cross-writer transaction: callers must serialize
     * start/append/finish and external writes, as the governed trial runtime already does.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function apply(string $root, string $file, array $input): array
    {
        $error = self::validate($input);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        $root = rtrim($root, '/');
        $relative = substr($file, strlen($root) + 1);
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || !str_starts_with($file, $root . '/')
            || !preg_match('~^(?:src|tests)/Plugins/[A-Za-z_][A-Za-z0-9_]*/~', $relative)
            || realpath($file) !== $canonicalRoot . '/' . $relative) {
            return ['ok' => false, 'error' => 'amend requires a scaffold inside its plugin tree without symlink redirection'];
        }
        $staging = $file . ImplementHandler::STAGING_SUFFIX;
        if (is_link($staging)) {
            return ['ok' => false, 'error' => 'amend refuses a symlink staging file'];
        }
        if (!is_file($staging)) {
            return ['ok' => false, 'error' => 'nothing to amend: no staged work; open it with mode=start first'];
        }
        $handle = @fopen($staging, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'could not read staging for amendment'];
        }
        $temporary = null;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return ['ok' => false, 'error' => 'staging is busy; read its current bytes and hash after the other writer finishes'];
            }
            $stat = fstat($handle);
            $before = stream_get_contents($handle);
            if ($stat === false || $before === false || !self::sameFile($staging, $stat)) {
                return ['ok' => false, 'error' => 'staging changed identity or is linked; read its current state before amending'];
            }
            $digest = hash('sha256', $before);
            if ($digest !== $input['expected_sha256']) {
                return ['ok' => false, 'error' => 'staging hash mismatch; read the current staging before rebuilding your edits',
                    'sha256' => $digest];
            }
            $patch = EditPairs::apply($before, $input['edits'], 'CURRENT staging');
            if (!$patch['ok']) {
                return $patch;
            }
            $temporary = tempnam(dirname($staging), '.milpa-amend-');
            if ($temporary === false) {
                return ['ok' => false, 'error' => 'could not prepare staging amendment'];
            }
            if (file_put_contents($temporary, $patch['content']) !== strlen($patch['content'])
                || !chmod($temporary, $stat['mode'] & 0o777)) {
                return ['ok' => false, 'error' => 'could not write staging amendment; original staging preserved'];
            }
            rewind($handle);
            if (!self::sameFile($staging, $stat) || stream_get_contents($handle) !== $before) {
                return ['ok' => false, 'error' => 'staging changed during amendment; original candidate was not replaced'];
            }
            if (!rename($temporary, $staging)) {
                return ['ok' => false, 'error' => 'could not replace staging; original candidate preserved'];
            }
            return [
                'ok' => true,
                'file' => $relative,
                'staging' => $relative . ImplementHandler::STAGING_SUFFIX,
                'before_sha256' => $digest,
                'sha256' => hash('sha256', $patch['content']),
                'edits_applied' => $patch['edits_applied'],
                'partial' => 'amended staging only — nothing verified, nothing judged; live PHP is untouched. '
                    . 'Apply this staged change through the host promotion flow, then mode=finish verifies and judges it.',
            ];
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            fclose($handle);
        }
    }

    /**
     * Refuse replacement, redirection or hardlinks to the opened regular staging inode.
     *
     * @phpstan-impure
     *
     * @param array<string|int, int> $opened
     */
    private static function sameFile(string $path, array $opened): bool
    {
        clearstatcache(true, $path);
        $current = lstat($path);
        return $current !== false && ($current['mode'] & 0o170000) === 0o100000
            && $current['nlink'] === 1 && $current['dev'] === $opened['dev'] && $current['ino'] === $opened['ino'];
    }
}
