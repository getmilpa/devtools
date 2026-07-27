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

use Milpa\DevTools\Board\Artifact;
use Milpa\DevTools\Board\Board;
use Milpa\DevTools\Board\Check;
use Milpa\DevTools\Board\Cost;
use Milpa\DevTools\Board\Outcome;
use PHPUnit\Framework\TestCase;

/**
 * El tablero.
 *
 * Casi todo lo que se prueba aquí es una prohibición de ADR-0028, y cada una nació de un error real
 * del mismo día: una comprobación que reventó y truncó el reporte, un OOM leído como defecto, un
 * resultado ausente leído como resultado bueno. Un tablero que se equivoca en esas tres es peor que
 * no tener tablero, porque se le cree.
 */
final class BoardTest extends TestCase
{
    private function check(string $id, Outcome $outcome, array $needs = [], bool $human = false, Cost $cost = Cost::Fast): Check
    {
        return new Check(
            id: $id,
            claim: "hecho {$id}",
            artifact: Artifact::Working,
            probe: static fn (): Outcome => $outcome,
            needs: $needs,
            human: $human,
            cost: $cost,
        );
    }

    // ---- lo que ADR-0028 prohíbe -------------------------------------------------------

    public function test_una_comprobacion_que_revienta_no_se_reporta_como_defecto(): void
    {
        // Es la distinción cara: "el sujeto está mal" contra "el instrumento no midió". Colapsarlas
        // manda a alguien a arreglar algo que quizá ya está bien.
        $board = new Board([new Check(
            id: 'truena',
            claim: 'algo',
            artifact: Artifact::Tooling,
            probe: static fn (): Outcome => throw new \RuntimeException('phpstan se quedó sin memoria'),
        )]);

        $state = $board->run();

        self::assertSame(Outcome::Unmeasured, $state->readings['truena']->outcome);
        self::assertSame('phpstan se quedó sin memoria', $state->readings['truena']->note);
        self::assertCount(1, $state->unmeasured());
    }

    public function test_una_comprobacion_que_revienta_no_tumba_a_las_demas(): void
    {
        // En el slice mínimo un `exit` adentro de una prueba truncó el reporte y las seis
        // comprobaciones que faltaban no salieron — lo cual se lee igualito que un tablero limpio.
        $board = new Board([
            $this->check('antes', Outcome::Passed),
            new Check('truena', 'algo', Artifact::Working, static fn (): Outcome => throw new \Error('boom')),
            $this->check('despues', Outcome::Passed),
        ]);

        $state = $board->run();

        self::assertCount(3, $state->readings, 'Las tres tienen que aparecer.');
        self::assertCount(2, $state->done());
    }

    public function test_lo_no_medido_jamas_cuenta_como_hecho(): void
    {
        $board = new Board([new Check('x', 'algo', Artifact::Published, static fn (): Outcome => Outcome::Unmeasured)]);

        $state = $board->run();

        self::assertSame([], $state->done());
        self::assertCount(1, $state->unmeasured());
    }

    public function test_lo_que_no_se_corrio_por_costo_entra_como_no_medido_y_no_se_omite(): void
    {
        // Un resultado ausente se lee como un resultado bueno. Omitirlo sería la misma mentira que
        // el ADR prohíbe, sólo que más silenciosa.
        $board = new Board([
            $this->check('barata', Outcome::Passed),
            $this->check('cara', Outcome::Passed, cost: Cost::Slow),
        ]);

        $state = $board->run([Cost::Fast]);

        self::assertSame(Outcome::Unmeasured, $state->readings['cara']->outcome);
        self::assertStringContainsString('costo slow', (string) $state->readings['cara']->note);
        self::assertCount(1, $state->done());
    }

    public function test_cada_lectura_dice_de_que_artefacto_saco_su_verdad(): void
    {
        // Sin esto, una alarma no se puede refutar sin adivinar dónde miró.
        $board = new Board([new Check('pins', 'sin comodines', Artifact::Published, static fn (): Outcome => Outcome::Failed)]);

        $line = $board->run()->readings['pins']->line();

        self::assertStringContainsString('[publicado]', $line);
    }

    // ---- las columnas se derivan -------------------------------------------------------

    public function test_un_pendiente_sin_bloqueadores_esta_listo_para_trabajar(): void
    {
        $board = new Board([$this->check('gate', Outcome::Failed)]);

        $state = $board->run();

        self::assertSame(['gate'], array_map(static fn ($r) => $r->check->id, $state->ready()));
        self::assertSame([], $state->blocked());
    }

    public function test_un_pendiente_cuya_dependencia_falta_esta_bloqueado_y_dice_por_cual(): void
    {
        $board = new Board([
            $this->check('firmar', Outcome::Failed),
            $this->check('publicar', Outcome::Failed, needs: ['firmar']),
        ]);

        $state = $board->run();

        self::assertSame(['publicar'], array_map(static fn ($r) => $r->check->id, $state->blocked()));
        self::assertSame(['firmar'], $state->blockers['publicar']);
    }

    public function test_cuando_la_dependencia_se_cumple_el_pendiente_pasa_a_listo(): void
    {
        $board = new Board([
            $this->check('firmar', Outcome::Passed),
            $this->check('publicar', Outcome::Failed, needs: ['firmar']),
        ]);

        $state = $board->run();

        self::assertSame(['publicar'], array_map(static fn ($r) => $r->check->id, $state->ready()));
        self::assertSame([], $state->blockers['publicar']);
    }

    public function test_solo_se_reportan_los_bloqueadores_directos(): void
    {
        // Una lista transitiva entierra al culpable inmediato entre sus abuelos.
        $board = new Board([
            $this->check('a', Outcome::Failed),
            $this->check('b', Outcome::Failed, needs: ['a']),
            $this->check('c', Outcome::Failed, needs: ['b']),
        ]);

        self::assertSame(['b'], $board->run()->blockers['c']);
    }

    public function test_lo_humano_va_en_su_propia_columna_y_nunca_aparece_como_trabajable(): void
    {
        // La mitad de lo pendiente aquí es una llave o una decisión. Mezclarlo con lo accionable
        // hace que el tablero grite cosas que ningún trabajo mueve, y a los dos días nadie lo lee.
        $board = new Board([$this->check('firmar', Outcome::Failed, human: true)]);

        $state = $board->run();

        self::assertSame(['firmar'], array_map(static fn ($r) => $r->check->id, $state->blockedOnHuman()));
        self::assertSame([], $state->ready());
        self::assertSame([], $state->blocked());
    }

    public function test_lo_bloqueado_por_algo_humano_sigue_siendo_bloqueado_normal(): void
    {
        // El trabajo en sí no es humano; lo es su dependencia. La distinción importa porque dice
        // a quién le toca destrabarlo.
        $board = new Board([
            $this->check('firmar', Outcome::Failed, human: true),
            $this->check('publicar', Outcome::Failed, needs: ['firmar']),
        ]);

        $state = $board->run();

        self::assertSame(['firmar'], array_map(static fn ($r) => $r->check->id, $state->blockedOnHuman()));
        self::assertSame(['publicar'], array_map(static fn ($r) => $r->check->id, $state->blocked()));
    }

    // ---- errores de quien declara ------------------------------------------------------

    public function test_una_dependencia_hacia_algo_inexistente_truena_al_correr_no_al_leer(): void
    {
        // Si se ignorara, la comprobación aparecería como lista para trabajar cuando en realidad
        // nadie sabe qué la bloquea — el peor resultado posible.
        $board = new Board([$this->check('publicar', Outcome::Failed, needs: ['fantasma'])]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'publicar' depende de 'fantasma'");

        $board->run();
    }

    public function test_dos_comprobaciones_con_el_mismo_id_se_rechazan(): void
    {
        $board = new Board([$this->check('x', Outcome::Passed)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("id 'x'");

        $board->add($this->check('x', Outcome::Failed));
    }
}
