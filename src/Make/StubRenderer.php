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

namespace Milpa\DevTools\Make;

/**
 * Renders a `*.php.stub` template: replaces every `{{key}}` token with the matching value. Generators
 * compose repeated blocks (e.g. per-field properties) by rendering a partial stub per item and
 * joining the result, then feed it in as a single placeholder value.
 */
final class StubRenderer
{
    /**
     * Renders `$stubPath`, replacing every `{{key}}` token from `$vars`; throws if any placeholder
     * is left unreplaced.
     *
     * @param array<string, string> $vars
     */
    public function render(string $stubPath, array $vars): string
    {
        $template = file_get_contents($stubPath);
        if ($template === false) {
            throw new \RuntimeException("cannot read stub: {$stubPath}");
        }

        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        if (preg_match('/{{(\w+)}}/', $template, $m) === 1) {
            throw new \RuntimeException("unreplaced placeholder {{{$m[1]}}} in {$stubPath}");
        }

        return $template;
    }
}
