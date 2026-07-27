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

namespace Milpa\DevTools\Tests\Board;

use Milpa\DevTools\Board\Cost;
use Milpa\DevTools\Board\FamilyBoard;
use Milpa\DevTools\Board\Outcome;
use Milpa\DevTools\Board\Shell;
use PHPUnit\Framework\TestCase;

/**
 * El tablero real, contra un disco de mentiras y una red de mentiras.
 *
 * Que estos tests NO salgan a internet no es comodidad: un tablero cuyos tests dependen de la red
 * empieza a fallar por razones que no son suyas, y a la tercera vez alguien aprende a ignorar el
 * rojo. Que sea sustituible es la razón por la que {@see Shell} existe.
 */
final class FamilyBoardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/milpa-board-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/getmilpa-plugin', 0777, true);
        mkdir($this->root . '/teamx/packages/milpa-http-symfony', 0777, true);
        mkdir($this->root . '/teamx/packages/milpa-admin', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    /**
     * @param array<string, ?string> $urls
     */
    private function shell(?string $gitOutput = '', array $urls = []): Shell
    {
        return new class ($gitOutput, $urls) extends Shell {
            /** @param array<string, ?string> $urls */
            public function __construct(private readonly ?string $gitOutput, private readonly array $urls)
            {
            }

            public function run(array $command): ?string
            {
                return $this->gitOutput;
            }

            public function fetch(string $url): ?string
            {
                return $this->urls[$url] ?? null;
            }
        };
    }

    private function packagistJson(string $package, string ...$versions): string
    {
        return json_encode([
            'packages' => ['milpa/' . $package => array_map(
                static fn (string $v): array => ['version' => $v],
                $versions,
            )],
        ], \JSON_THROW_ON_ERROR);
    }

    private function state(Shell $shell): \Milpa\DevTools\Board\BoardState
    {
        return (new FamilyBoard($this->root, $shell))->build()->run();
    }

    public function test_commits_todos_firmados_pasan(): void
    {
        $state = $this->state($this->shell("G\nG\nG\n"));

        self::assertSame(Outcome::Passed, $state->readings['plugin:firmado']->outcome);
    }

    public function test_un_solo_commit_sin_firma_reprueba_al_repo_entero(): void
    {
        // Una sola firma faltante rompe la cadena: no hay "casi firmado".
        $state = $this->state($this->shell("G\nN\nG\n"));

        self::assertSame(Outcome::Failed, $state->readings['plugin:firmado']->outcome);
    }

    public function test_sin_commits_pendientes_el_repo_cuenta_como_firmado(): void
    {
        // Nada que firmar es un estado bueno, no uno desconocido.
        $state = $this->state($this->shell(''));

        self::assertSame(Outcome::Passed, $state->readings['plugin:firmado']->outcome);
    }

    public function test_si_git_no_pudo_correr_no_se_dice_que_falta_firmar(): void
    {
        // No poder preguntar no es lo mismo que una respuesta mala (ADR-0028): reportarlo como
        // "sin firmar" mandaría a Rod a sacar la YubiKey por nada.
        $state = $this->state($this->shell(null));

        self::assertSame(Outcome::Unmeasured, $state->readings['plugin:firmado']->outcome);
    }

    public function test_la_version_publicada_se_pregunta_al_indice_no_a_los_tags(): void
    {
        $shell = $this->shell('', [
            'https://repo.packagist.org/p2/milpa/plugin.json' => $this->packagistJson('plugin', 'v0.3.0', 'v0.2.0'),
        ]);

        $state = $this->state($shell);

        self::assertSame(Outcome::Passed, $state->readings['plugin:publicado-0.3']->outcome);
    }

    public function test_una_version_que_el_indice_no_sirve_esta_pendiente_aunque_exista_local(): void
    {
        $shell = $this->shell('', [
            'https://repo.packagist.org/p2/milpa/plugin.json' => $this->packagistJson('plugin', 'v0.2.0'),
        ]);

        self::assertSame(Outcome::Failed, $this->state($shell)->readings['plugin:publicado-0.3']->outcome);
    }

    public function test_sin_red_la_version_publicada_queda_sin_medir_y_no_como_pendiente(): void
    {
        // Es la diferencia entre "todavía no sale" y "no sé si salió". La segunda no es trabajo.
        $state = $this->state($this->shell('', []));

        self::assertSame(Outcome::Unmeasured, $state->readings['plugin:publicado-0.3']->outcome);
        self::assertNotContains(
            'plugin:publicado-0.3',
            array_map(static fn ($r) => $r->check->id, $state->blocked()),
        );
    }

    public function test_un_paquete_que_el_indice_no_conoce_esta_pendiente_no_sin_medir(): void
    {
        // Un 404 SÍ es una respuesta: el paquete no existe. Distinto de no haber podido preguntar.
        $shell = $this->shell('', ['https://repo.packagist.org/p2/milpa/http-symfony.json' => null]);

        self::assertSame(Outcome::Failed, $this->state($shell)->readings['http-symfony:publicado']->outcome);
    }

    public function test_el_andamiaje_de_publicacion_exige_los_cinco_archivos(): void
    {
        $dir = $this->root . '/teamx/packages/milpa-http-symfony';
        foreach (['LICENSE', 'NOTICE', 'README.md'] as $f) {
            file_put_contents($dir . '/' . $f, 'contenido real');
        }
        mkdir($dir . '/.github');
        file_put_contents($dir . '/.github/ci.yml', 'jobs:');

        // Falta `tools`: cuatro de cinco no es empaquetable.
        self::assertSame(Outcome::Failed, $this->state($this->shell())->readings['http-symfony:empaquetable']->outcome);

        // Y un `tools/` VACÍO tampoco cuenta: existir no es servir (ADR-0029).
        mkdir($dir . '/tools');
        self::assertSame(Outcome::Failed, $this->state($this->shell())->readings['http-symfony:empaquetable']->outcome);

        file_put_contents($dir . '/tools/coverage-floor.php', '<?php');
        self::assertSame(Outcome::Passed, $this->state($this->shell())->readings['http-symfony:empaquetable']->outcome);
    }

    public function test_una_sola_clase_del_host_vuelve_impotable_al_paquete(): void
    {
        file_put_contents($this->root . '/teamx/packages/milpa-admin/Limpio.php', '<?php class Limpio {}');
        self::assertSame(Outcome::Passed, $this->state($this->shell())->readings['admin:portable']->outcome);

        file_put_contents(
            $this->root . '/teamx/packages/milpa-admin/Sucio.php',
            '<?php use Milpa\\app\\Http\\Routing\\RouteTableAssembler;',
        );
        self::assertSame(Outcome::Failed, $this->state($this->shell())->readings['admin:portable']->outcome);
    }

    public function test_un_paquete_que_no_esta_en_el_disco_queda_sin_medir(): void
    {
        // El directorio no existe: no se puede afirmar que sea portable ni que no lo sea.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root . '/teamx/packages/milpa-admin', \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($this->root . '/teamx/packages/milpa-admin');

        self::assertSame(Outcome::Unmeasured, $this->state($this->shell())->readings['admin:portable']->outcome);
    }

    public function test_un_repo_sin_NOTICE_reprueba_a_la_familia(): void
    {
        // El setUp crea getmilpa-plugin y nadie le puso NOTICE.
        self::assertSame(Outcome::Failed, $this->state($this->shell())->readings['familia:con-NOTICE']->outcome);

        file_put_contents($this->root . '/getmilpa-plugin/NOTICE', '(c) Rodrigo Vicente - TeamX Agency');
        self::assertSame(Outcome::Passed, $this->state($this->shell())->readings['familia:con-NOTICE']->outcome);
    }

    public function test_cero_repos_no_se_reporta_como_todos_cumplen(): void
    {
        // Es la mentira más fácil de escribir: un bucle sobre una lista vacía "pasa" siempre.
        // Sin repos que revisar no hay nada que afirmar.
        rmdir($this->root . '/getmilpa-plugin');

        self::assertSame(Outcome::Unmeasured, $this->state($this->shell())->readings['familia:con-NOTICE']->outcome);
    }

    public function test_correr_solo_lo_barato_deja_lo_de_red_sin_medir_pero_visible(): void
    {
        $state = (new FamilyBoard($this->root, $this->shell()))->build()->run([Cost::Fast]);

        self::assertSame(Outcome::Unmeasured, $state->readings['plugin:publicado-0.3']->outcome);
        self::assertStringContainsString('costo network', (string) $state->readings['plugin:publicado-0.3']->note);
    }

    // ---- ADR-0029: ninguna comprobación pasa sobre la nada -----------------------------

    public function test_ninguna_comprobacion_pasa_sobre_un_estado_vacio(): void
    {
        // El falsificador del ADR-0029, congelado. Se construye el estado donde toda afirmación es
        // falsa —archivos de andamiaje en blanco, un NOTICE vacío, un gate de cero bytes, un
        // paquete sin un solo archivo— y se exige que NINGUNA dé verde.
        //
        // Antes de escribirlo pasaban cinco de cinco. `admin:portable` era el más elocuente: "no
        // toca clases del host" es trivialmente cierto de un paquete vacío.
        foreach (['LICENSE', 'NOTICE', 'README.md'] as $blank) {
            file_put_contents($this->root . "/teamx/packages/milpa-http-symfony/{$blank}", '');
            file_put_contents($this->root . "/teamx/packages/milpa-admin/{$blank}", '');
        }
        foreach (['milpa-http-symfony', 'milpa-admin'] as $pkg) {
            mkdir($this->root . "/teamx/packages/{$pkg}/.github");
            mkdir($this->root . "/teamx/packages/{$pkg}/tools");
        }
        file_put_contents($this->root . '/getmilpa-plugin/NOTICE', '');
        mkdir($this->root . '/teamx/scripts/library', 0777, true);
        file_put_contents($this->root . '/teamx/scripts/library/verify-no-divergence.sh', '');

        $state = $this->state($this->shell());

        foreach (['familia:con-NOTICE', 'http-symfony:empaquetable', 'admin:empaquetable', 'gate:divergencia', 'admin:portable'] as $id) {
            self::assertNotSame(
                Outcome::Passed,
                $state->readings[$id]->outcome,
                "{$id} pasa sobre la nada: verifica un proxy más barato que su nombre (ADR-0029).",
            );
        }
    }

    public function test_un_NOTICE_sin_la_atribucion_no_cuenta_como_NOTICE(): void
    {
        // Existir no es conservar. El NOTICE es el único vector que Apache-2.0 §4(d) obliga a
        // preservar, y un archivo con cualquier otro texto satisface `-f` sin preservar nada.
        file_put_contents($this->root . '/getmilpa-plugin/NOTICE', "Copyright de alguien más\n");
        self::assertSame(Outcome::Failed, $this->state($this->shell())->readings['familia:con-NOTICE']->outcome);

        file_put_contents($this->root . '/getmilpa-plugin/NOTICE', "(c) Rodrigo Vicente - TeamX Agency\n");
        self::assertSame(Outcome::Passed, $this->state($this->shell())->readings['familia:con-NOTICE']->outcome);
    }
}
