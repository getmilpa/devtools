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

use Milpa\DevTools\Board\Shell;
use PHPUnit\Framework\TestCase;

/**
 * The board's two doors to the outside, run for real: a process and a fetch. What matters is the difference the class
 * exists to keep — an answer that is empty is a string, a question that got no answer is null.
 */
final class ShellTest extends TestCase
{
    public function testAProcessThatRunsHandsBackItsOutput(): void
    {
        self::assertSame("milpa\n", (new Shell())->run([\PHP_BINARY, '-r', 'echo "milpa\n";']));
    }

    public function testAProcessThatSaysNothingIsAnEmptyAnswerNotNoAnswer(): void
    {
        self::assertSame('', (new Shell())->run([\PHP_BINARY, '-r', '']));
    }

    public function testAProcessThatFailsIsNoAnswer(): void
    {
        self::assertNull((new Shell())->run([\PHP_BINARY, '-r', 'fwrite(STDOUT, "half"); exit(3);']), 'its output does not count');
    }

    public function testAProcessThatCannotStartIsNoAnswer(): void
    {
        self::assertNull((new Shell())->run(['/nonexistent/milpa-board-binary']));
    }

    public function testAFetchThatArrivesHandsBackTheBody(): void
    {
        $file = sys_get_temp_dir() . '/milpa-board-fetch-' . bin2hex(random_bytes(4));
        file_put_contents($file, '{"packages":[]}');

        try {
            self::assertSame('{"packages":[]}', (new Shell())->fetch($file));
        } finally {
            unlink($file);
        }
    }

    public function testAFetchThatNothingAnswersIsNoAnswer(): void
    {
        // Port 9 on the loopback: nothing listens, the connection is refused at once — no network leaves the machine.
        self::assertNull((new Shell())->fetch('http://127.0.0.1:9/index.json'));
    }
}
