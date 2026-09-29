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
 * What `make` says about the one step it leaves to its caller: registering a new plugin.
 *
 * Six generators and the postcondition verifier each wrote this sentence, and every copy named the
 * plugin by its fully qualified class name — «add App\Plugins\Blog\Blog::class to the list returned by
 * config/plugins.php» (greenhouse evidence/1036, seq 88). But the house registers by the SHORT name:
 * `plugins.register` takes `Blog` and resolves `src/Plugins/Blog/Blog.php`, and config/plugins.php
 * lists `Blog::class` under a `use`. A guide that dictates the long form teaches the model a name
 * the house never asks for (greenhouse decisions/0514). One owner, so the copies cannot drift again.
 */
final class PluginRegistration
{
    /**
     * The guidance for a plugin `make` just created: register it by its short name.
     *
     * @param string $plugin the plugin's short class name, e.g. `Blog`
     */
    public static function guidance(string $plugin): string
    {
        return "New plugin — register it so the kernel boots it: run `plugins.register` with name={$plugin} "
            . "(it lists {$plugin}::class in config/plugins.php).";
    }

    /**
     * The postcondition's advice when the plugin is not listed yet — same step, same short name.
     *
     * @param string $plugin the plugin's short class name, e.g. `Blog`
     */
    public static function notListed(string $plugin): string
    {
        return "plugin not yet listed in config/plugins.php — register it with `plugins.register` name={$plugin} "
            . '(make leaves this activation to you)';
    }
}
