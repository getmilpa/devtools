<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\DevTools\Operations;

use Milpa\Command\InvocationContext;
use Milpa\DevTools\Support\RootResolver;
use Milpa\DevTools\Support\SourcePath;
use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\Contracts\ToolContext;

/** Complete JSON pages of UTF-8 source; the cursor binds a byte position to path and content. */
final class SourcePageHandler
{
    public function __construct(private readonly RootResolver $roots = new RootResolver())
    {
    }

    /**
     * The caller supplies a budget outside a model transport; an explicit bound only tightens it.
     * Each invocation rereads the complete file to reject a cursor for changed content.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function handle(array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array
    {
        $budget = $authority?->resultBudget;
        if (\array_key_exists('max_chars', $input)) {
            if (!\is_int($input['max_chars']) || $input['max_chars'] < 256) {
                return ['ok' => false, 'error' => 'max_chars must be an integer of at least 256'];
            }
            $budget = $budget?->tightenedTo($input['max_chars']) ?? ResultBudget::json($input['max_chars']);
        }
        if ($budget === null) {
            return ['ok' => false, 'error' => 'source:page needs a transport result budget or explicit max_chars'];
        }
        $path = \is_string($input['path'] ?? null) ? trim($input['path']) : '';
        $root = $this->roots->resolve();
        $file = $path === '' ? null : SourcePath::inside($root, $path);
        if ($file === null) {
            return ['ok' => false, 'error' => 'path must name a file inside the app root'];
        }
        $path = SourcePath::relative($file, $root);
        $content = file_get_contents($file);
        if ($content === false || !mb_check_encoding($content, 'UTF-8')) {
            return ['ok' => false, 'error' => 'source must be readable UTF-8'];
        }
        $digest = hash('sha256', $content);
        $total = \strlen($content);
        $offset = 0;
        if (\array_key_exists('cursor', $input)) {
            $cursor = $this->decodeCursor($input['cursor']);
            if ($cursor === null) {
                return ['ok' => false, 'error' => 'invalid source cursor'];
            }
            if ($cursor['path'] !== $path || $cursor['sha256'] !== $digest) {
                return ['ok' => false, 'error' => 'source cursor does not match this path and content; restart the read'];
            }
            $offset = $cursor['offset'];
            if ($offset < 0 || $offset > $total || !mb_check_encoding(substr($content, 0, $offset), 'UTF-8')) {
                return ['ok' => false, 'error' => 'source cursor offset must be a UTF-8 byte boundary inside the file'];
            }
        }
        $remaining = substr($content, $offset);
        $page = function (string $part) use ($path, $digest, $offset, $total): array {
            $next = $offset + \strlen($part);

            return ['ok' => true, 'path' => $path, 'sha256' => $digest, 'offset' => $offset,
                'next_offset' => $next, 'total_bytes' => $total, 'content' => $part,
                'next_cursor' => $next === $total ? null : $this->encodeCursor($path, $digest, $next)];
        };
        $full = $page($remaining);
        if ($budget->fits($full)) {
            return $full;
        }
        $low = 0;
        $high = mb_strlen($remaining, 'UTF-8');
        $best = null;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = $page(mb_substr($remaining, 0, $mid, 'UTF-8'));
            if ($budget->fits($candidate)) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        if ($best === null || $best['next_offset'] === $offset) {
            return ['ok' => false, 'error' => 'result budget cannot fit source metadata and one character'];
        }

        return $best;
    }

    /** A continuation token carries identity and position, never authorization. */
    private function encodeCursor(string $path, string $digest, int $offset): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'path' => $path, 'sha256' => $digest, 'offset' => $offset], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{v: int, path: string, sha256: string, offset: int}|null */
    private function decodeCursor(mixed $token): ?array
    {
        if (!\is_string($token) || $token === '' || \strlen($token) > 16384 || preg_match('/[^a-zA-Z0-9_-]/', $token)) {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        $cursor = $raw === false ? null : json_decode($raw, true);
        if (!\is_array($cursor) || \count($cursor) !== 4 || ($cursor['v'] ?? null) !== 1
            || !\is_string($cursor['path'] ?? null) || !\is_string($cursor['sha256'] ?? null)
            || !\is_int($cursor['offset'] ?? null)) {
            return null;
        }

        return $cursor;
    }
}
