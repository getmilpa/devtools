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
 * A reading operation of this package is given to a session, and it returns a slice of any file inside the app
 * root. Inside the root sit the files where the house keeps a secret: its environment file and the family beside
 * it (`.env`, `.env.local`, `.env.production`…), the envelope a declared key is written to, and Composer's
 * credentials. A read does not hand those back — nor a COPY of one a trial or a boot candidate kept.
 *
 * `HouseWork` already refuses to write any file whose name begins with `.env`; this says the same of a read, a
 * copy and the runner's mask (greenhouse evidence/1161). The named templates are not secrets: they are the
 * placeholders a project commits, and they stay readable.
 */
final class SecretFiles
{
    /** The envelope and Composer's credentials, matched by the tail of a path. */
    private const TAIL = ['.milpa/secrets.json', 'auth.json'];

    /** `.env*` names that are placeholders, not secrets. */
    private const TEMPLATES = ['.env.example', '.env.dist', '.env.template', '.env.sample'];

    /**
     * Whether a path from the root of a house names a file the house keeps a secret in — the file itself, OR a
     * copy of it anywhere below (a trial under `var/trials/<id>/copy/`, a boot candidate under
     * `var/boot-candidates/<id>/`). Matched case-insensitively: a case-insensitive filesystem (macOS, the
     * Desktop's) resolves `.ENV` and `.Milpa/Secrets.json` to the real file, so a match that was byte-exact
     * would miss them.
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

    /** Whether a file already admitted by {@see SourcePath::inside()} is one the house keeps a secret in. */
    public static function holds(string $file, string $root): bool
    {
        return self::isSecret(SourcePath::relative($file, $root));
    }
}
