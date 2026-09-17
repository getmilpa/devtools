<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Operations;

/** The landing gate's namespace transformation, shared with its host-side witness. */
final class ImplementationBody
{
    /** Resolve the namespace dictated by the relative source/test path. */
    public static function namespaceFor(string $path): string
    {
        return (str_starts_with($path, 'tests/') ? 'App\\Tests\\' : 'App\\')
            . str_replace('/', '\\', dirname(substr($path, str_starts_with($path, 'tests/') ? 6 : 4)));
    }

    /** Apply exactly the transformation used before syntax and behavior are judged. */
    public static function normalize(string $content, string $path): string
    {
        $namespace = self::namespaceFor($path);
        if (preg_match('/^namespace\s+[^;]+;/m', $content) === 1) {
            $content = (string) preg_replace('/^namespace\s+[^;]+;/m', "namespace {$namespace};", $content, 1);
        } else {
            // No namespace at all — inject it after the opening tag and its declare(), if present.
            $content = (string) preg_replace(
                '/\A(<\?php\b[^\n]*\n(?:\s*declare\s*\([^)]*\)\s*;\s*\n)?)/',
                "$1\nnamespace {$namespace};\n",
                $content,
                1
            );
        }
        return $content;
    }
}
