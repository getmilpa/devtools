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

/**
 * Generates the static API reference site for milpa/devtools.
 *
 * Thin entry over the family docs generator (`Milpa\Docs\SiteGenerator`, shipped inside the
 * milpa/core dist this package already requires): reflects over `src/`, renders one `mui-api`-styled
 * page per public type wrapped in the `mui-docs` shell, a nav, a per-page table of contents, and
 * `index.html`. Branding is passed in as a {@see Milpa\Docs\SiteConfig} — this package is a
 * `SiteConfig` consumer from day one, not a post-process `strtr()` rebrand over core's own
 * "Milpa Core"-branded output (see the family's earlier `tools/gen-docs.php` entries for that
 * older pattern).
 *
 * Usage: php tools/gen-docs.php --out <dir> [--css-base <url>] [--version <v>]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Required-value long options (`name:`, not `name::`) so `--css-base /ds` with a
// space is captured; optional (`::`) only binds `--css-base=/ds`. getopt yields
// `false` for a flag it can't bind a value to, so guard with is_string, not `??`
// (which only rescues null) before falling back to the default.
$opts = getopt('', ['out:', 'css-base:', 'version:']);
$out = is_string($opts['out'] ?? null) ? $opts['out'] : 'build/docs';
$cssBase = is_string($opts['css-base'] ?? null) ? $opts['css-base'] : 'https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0';

// Version shown in the docs chrome (topbar badge, title, footer). Prefer an
// explicit --version; otherwise read the release-please manifest (present in
// the published repo); fall back to "dev" for local builds.
$version = is_string($opts['version'] ?? null) ? $opts['version'] : null;
if ($version === null) {
    $manifest = dirname(__DIR__) . '/.github/.release-please-manifest.json';
    $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $version = is_array($data) && is_string($data['.'] ?? null) ? $data['.'] : 'dev';
}

$config = new Milpa\Docs\SiteConfig(
    brand: 'Milpa DevTools',
    nsPrefix: 'Milpa\\DevTools\\',
    installCommand: 'composer require milpa/devtools',
    repoUrl: 'https://github.com/getmilpa/devtools',
    pagesUrl: 'https://getmilpa.github.io/devtools/',
    heroParagraph: 'The <strong>generate-verify-inspect</strong> developer loop for Milpa apps — code '
        . 'generators, boot-time doctors, and architectural validators that run <strong>in-process</strong>, '
        . 'with Composer-safe root resolution. No product coupling, no <code>exec()</code>: the same '
        . 'convention checks <code>coa:make</code> runs against its own freshly generated code are '
        . 'yours to call directly.',
    utmContent: 'devtools',
);

$count = (new Milpa\Docs\SiteGenerator(dirname(__DIR__) . '/src', $out, $cssBase, $version, $config))->generate();

echo "generated {$count} page(s) to {$out} (v{$version}, css-base: {$cssBase})\n";
exit(0);
