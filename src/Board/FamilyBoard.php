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

namespace Milpa\DevTools\Board;

/**
 * El tablero real de la familia Milpa.
 *
 * Aquí viven las dos cosas que el motor no puede saber: qué hechos importan, y las pocas aristas de
 * intención entre ellos. Todo lo demás se deriva.
 *
 * La proporción es el punto: catorce hechos comprobables contra cuatro líneas de intención, y esas
 * cuatro crecen por acoplamiento — no por repo. Es lo que hace que esto escale donde los archivos
 * en prosa no escalaron.
 */
final readonly class FamilyBoard
{
    public function __construct(
        private string $platformRoot,
        private Shell $shell = new Shell(),
    ) {
    }

    /**
     * Arma el tablero contra un directorio que contiene los repos de la familia.
     */
    public function build(): Board
    {
        return new Board([
            // ── lo que sólo Rod puede mover ────────────────────────────────────────────
            new Check(
                id: 'plugin:firmado',
                claim: 'los commits de milpa/plugin están firmados',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->everyCommitSigned('getmilpa-plugin'),
                human: true,
            ),
            new Check(
                id: 'teamx:firmado',
                claim: 'los commits del host están firmados',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->everyCommitSigned('teamx'),
                human: true,
            ),
            new Check(
                id: 'skeleton:firmado',
                claim: 'los commits del skeleton están firmados',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->everyCommitSigned('getmilpa-skeleton'),
                human: true,
            ),

            // ── la cadena de release ───────────────────────────────────────────────────
            new Check(
                id: 'plugin:publicado-0.3',
                claim: 'milpa/plugin 0.3.0 está en el índice',
                artifact: Artifact::Published,
                probe: fn (): Outcome => $this->packageHasVersion('plugin', 'v0.3.0'),
                needs: ['plugin:firmado'],
                cost: Cost::Network,
            ),
            new Check(
                id: 'skeleton:instalable',
                claim: 'el skeleton resuelve sus dependencias desde el índice',
                artifact: Artifact::Published,
                // Pide ^0.3 y esa versión no existe todavía: un `create-project` fallaría.
                probe: fn (): Outcome => $this->packageHasVersion('plugin', 'v0.3.0'),
                needs: ['plugin:publicado-0.3', 'skeleton:firmado'],
                cost: Cost::Network,
            ),
            new Check(
                id: 'http-symfony:publicado',
                claim: 'milpa/http-symfony existe en el índice',
                artifact: Artifact::Published,
                probe: fn (): Outcome => $this->packageExists('http-symfony'),
                needs: ['http-symfony:empaquetable'],
                cost: Cost::Network,
            ),
            new Check(
                id: 'admin:publicable',
                claim: 'milpa/admin puede salir',
                artifact: Artifact::Published,
                probe: fn (): Outcome => $this->packageExists('admin'),
                needs: ['http-symfony:publicado', 'admin:suite-verde', 'admin:empaquetable'],
                cost: Cost::Network,
            ),

            // ── trabajo derivable, sin humanos de por medio ────────────────────────────
            new Check(
                id: 'admin:empaquetable',
                claim: 'milpa/admin tiene su andamiaje de publicación',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->allExist('teamx/packages/milpa-admin', [
                    'LICENSE', 'NOTICE', 'README.md', '.github', 'tools',
                ]),
            ),
            new Check(
                id: 'http-symfony:empaquetable',
                claim: 'milpa/http-symfony tiene su andamiaje de publicación',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->allExist('teamx/packages/milpa-http-symfony', [
                    'LICENSE', 'NOTICE', 'README.md', '.github', 'tools',
                ]),
            ),
            new Check(
                id: 'admin:portable',
                claim: 'milpa/admin no toca clases del host',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->absentFrom('teamx/packages/milpa-admin', 'Milpa\\app'),
            ),
            new Check(
                id: 'admin:suite-verde',
                claim: 'la suite de milpa/admin corre sola',
                // No basta con que no haya imports del host: un paquete cuyos tests no pasan no es
                // publicable, y "portable" a secas se cumplía con sólo borrar los `use`. La
                // comprobación de arriba decía que sí mientras la suite tenía once fallas.
                artifact: Artifact::Tooling,
                probe: fn (): Outcome => $this->suitePasses('teamx/packages/milpa-admin'),
                needs: ['admin:portable'],
                cost: Cost::Slow,
            ),
            new Check(
                id: 'runtime:estrategia-instalable',
                claim: 'la estrategia de plugins del runtime tiene con qué correr',
                // PluginsManagerBootStrategy usa Milpa\Plugin\PluginsManager, que no existió en
                // ningún milpa/plugin publicado hasta 0.3. Hasta que el índice lo sirva, el CI del
                // runtime publicado no puede probar esa estrategia — y una clase que no puede
                // correr contra ninguna versión liberada es una clase que sólo puede fallar.
                artifact: Artifact::Published,
                probe: fn (): Outcome => $this->packageHasVersion('plugin', 'v0.3.0'),
                needs: ['plugin:publicado-0.3'],
                cost: Cost::Network,
            ),
            new Check(
                id: 'gate:divergencia',
                claim: 'existe un gate que compara cada paquete con su repo publicado',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->runnable('teamx/scripts/library/verify-no-divergence.sh'),
            ),
            new Check(
                id: 'familia:sin-divergencia',
                claim: 'cada paquete coincide con su repo publicado',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->exportsMatchTheirRepos(),
                needs: ['gate:divergencia'],
                cost: Cost::Slow,
            ),
            new Check(
                id: 'familia:con-NOTICE',
                claim: 'todo repo publicado conserva su NOTICE',
                artifact: Artifact::Working,
                probe: fn (): Outcome => $this->everyRepoHas('NOTICE'),
            ),
        ]);
    }

    /**
     * Si la suite de un paquete corre en verde por su cuenta.
     *
     * Sin `vendor/` no se puede saber, y no saber no es lo mismo que fallar: un paquete recién
     * clonado no está roto, está sin instalar.
     */
    private function suitePasses(string $package): Outcome
    {
        $phpunit = $this->platformRoot . '/' . $package . '/vendor/bin/phpunit';
        $config = $this->platformRoot . '/' . $package . '/phpunit.xml';
        if (!is_file($phpunit) || !is_file($config)) {
            return Outcome::Unmeasured;
        }

        return $this->shell->run([$phpunit, '--configuration', $config]) === null
            ? Outcome::Failed
            : Outcome::Passed;
    }

    /**
     * Si correr cada export deja el repo publicado igual que como está.
     *
     * Es la comprobación que costó medio día no tener: el monorepo es la fuente y los `getmilpa-*`
     * el destino, pero nada los obligaba a coincidir. Cuando el gate corrió por primera vez, 23 de
     * 24 paquetes diferían — y algunos en las dos direcciones a la vez.
     */
    private function exportsMatchTheirRepos(): Outcome
    {
        $gate = $this->platformRoot . '/teamx/scripts/library/verify-no-divergence.sh';
        if (!is_file($gate)) {
            return Outcome::Unmeasured;
        }

        return $this->shell->run(['bash', $gate, '--quiet']) === null
            ? Outcome::Failed
            : Outcome::Passed;
    }

    /**
     * Si toda la familia publicada tiene el archivo.
     *
     * El glob se resuelve contra el árbol local a propósito: pregunta por los repos que ESTE
     * disco tiene, y así se declara. Saber lo mismo del índice costaría una consulta por paquete.
     */
    private function everyRepoHas(string $file): Outcome
    {
        $repos = glob($this->platformRoot . '/getmilpa-*', GLOB_ONLYDIR) ?: [];
        if ($repos === []) {
            return Outcome::Unmeasured;
        }

        foreach ($repos as $repo) {
            $path = $repo . '/' . $file;
            if (!is_file($path)) {
                return Outcome::Failed;
            }
            // El NOTICE responde por la ATRIBUCIÓN, no por existir: un archivo vacío satisface
            // `-f` y no conserva nada, que es justo lo que Apache-2.0 §4(d) obliga a preservar.
            if (!str_contains((string) file_get_contents($path), 'Rodrigo Vicente - TeamX Agency')) {
                return Outcome::Failed;
            }
        }

        return Outcome::Passed;
    }

    /** Si cada commit sin empujar trae firma GPG buena. */
    private function everyCommitSigned(string $repo): Outcome
    {
        $result = $this->shell->run(
            ['git', '-C', $this->platformRoot . '/' . $repo, 'log', '--format=%G?', 'origin/main..HEAD'],
        );
        if ($result === null) {
            return Outcome::Unmeasured;
        }

        $marks = array_filter(array_map('trim', explode("\n", $result)), static fn (string $m): bool => $m !== '');

        return array_filter($marks, static fn (string $m): bool => $m !== 'G') === []
            ? Outcome::Passed
            : Outcome::Failed;
    }

    /**
     * Si el índice sirve esa versión exacta.
     *
     * Contra el índice y no contra los tags locales: un tag existe en cuanto alguien lo escribe, y
     * lo que decide si un extraño puede instalar es lo que el índice sirve (ADR-0028).
     */
    private function packageHasVersion(string $package, string $version): Outcome
    {
        $versions = $this->publishedVersions($package);

        return $versions === null
            ? Outcome::Unmeasured
            : (\in_array($version, $versions, true) ? Outcome::Passed : Outcome::Failed);
    }

    private function packageExists(string $package): Outcome
    {
        $versions = $this->publishedVersions($package);

        // Un 404 del índice es una respuesta, no una falla de medición: el paquete no está.
        return $versions === null ? Outcome::Failed : Outcome::Passed;
    }

    /**
     * @return list<string>|null null cuando el índice no lo conoce
     */
    private function publishedVersions(string $package): ?array
    {
        $json = $this->shell->fetch("https://repo.packagist.org/p2/milpa/{$package}.json");
        if ($json === null) {
            return null;
        }

        try {
            /** @var array{packages: array<string, list<array{version: string}>>} $decoded */
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $releases = reset($decoded['packages']) ?: [];

        return array_map(static fn (array $r): string => $r['version'], $releases);
    }

    /**
     * Si cada archivo existe **y tiene contenido** (ADR-0029).
     *
     * La versión que sólo preguntaba `file_exists` pasaba sobre cinco archivos en blanco: decía
     * "tiene su andamiaje" de un paquete sin licencia, sin NOTICE y sin README.
     *
     * @param list<string> $files
     */
    private function allExist(string $dir, array $files): Outcome
    {
        foreach ($files as $file) {
            $path = $this->platformRoot . '/' . $dir . '/' . $file;
            if (!file_exists($path)) {
                return Outcome::Failed;
            }
            if (is_file($path) && trim((string) file_get_contents($path)) === '') {
                return Outcome::Failed;
            }
            if (is_dir($path) && (glob($path . '/*') ?: []) === []) {
                return Outcome::Failed;
            }
        }

        return Outcome::Passed;
    }

    /**
     * Si un script existe y de verdad puede correr (ADR-0029).
     *
     * `-x` sobre un archivo de cero bytes pasa, y un gate vacío "existe" mientras no verifica
     * nada: se le pide a bash que lo parsee, que es la prueba más barata de que hay algo adentro.
     */
    private function runnable(string $path): Outcome
    {
        $full = $this->platformRoot . '/' . $path;
        if (!is_file($full) || trim((string) file_get_contents($full)) === '') {
            return Outcome::Failed;
        }

        return $this->shell->run(['bash', '-n', $full]) === null ? Outcome::Failed : Outcome::Passed;
    }

    /** Si el texto NO aparece en ningún archivo del directorio. */
    private function absentFrom(string $dir, string $needle): Outcome
    {
        $path = $this->platformRoot . '/' . $dir;
        if (!is_dir($path)) {
            return Outcome::Unmeasured;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            ++$seen;
            if (str_contains((string) file_get_contents((string) $file), $needle)) {
                return Outcome::Failed;
            }
        }

        // Cero archivos revisados no es "portable": es un paquete que no está (ADR-0029). La
        // ausencia de imports del host es trivialmente cierta de la nada.
        return $seen === 0 ? Outcome::Unmeasured : Outcome::Passed;
    }
}
