<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Operations\ImplementationBody;
use Milpa\DevTools\Operations\SyntaxFinding;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/** A parser receipt proves a rejected proposal and a preserved target, never a rollback. */
final class SyntaxDiagnosticTest extends TestCase
{
    private string $root;
    private const SUBJECT = 'src/Plugins/Demo/Services/Greet.php';
    private const BODY = "<?php\ndeclare(strict_types=1);\nnamespace Wrong;\nclass Greet { public function broken( }\n";

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-syntax-receipt-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/' . dirname(self::SUBJECT), 0o700, true);
        file_put_contents($this->root . '/' . self::SUBJECT, '<?php // scaffold');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, mixed> */
    private function implement(string $body, ?string $mode = null): array
    {
        return (new ImplementHandler(new RootResolver($this->root)))->handle([
            'plugin' => 'Demo', 'class' => 'Greet', 'content' => $body, ...($mode === null ? [] : ['mode' => $mode]),
        ]);
    }

    public function testInlineAndFinishAttributeTheActualParserInputWithoutInstallingIt(): void
    {
        $result = $this->implement(self::BODY);
        self::assertFalse($result['ok']);
        $receipt = $result['diagnostic'];
        self::assertSame('syntax', $receipt['phase']);
        self::assertSame(self::SUBJECT, $receipt['subject']);
        self::assertSame(hash('sha256', self::BODY), $receipt['submitted_sha256']);
        self::assertSame(hash('sha256', ImplementationBody::normalize(self::BODY, self::SUBJECT)), $receipt['judged_sha256']);
        self::assertNotSame($receipt['submitted_sha256'], $receipt['judged_sha256']);
        self::assertSame(hash('sha256', '<?php // scaffold'), $receipt['preserved_sha256']);
        self::assertTrue($receipt['stable_subject']);
        self::assertTrue($receipt['destination_preserved']);
        self::assertFalse($receipt['candidate_installed']);
        self::assertArrayNotHasKey('rolled_back', $receipt);
        self::assertArrayNotHasKey('restored_sha256', $receipt);
        self::assertSame('php-token-parse/v1', $receipt['result']['parser']);
        self::assertSame("Unclosed '(' does not match '}'", $receipt['result']['message']);
        self::assertSame(4, $receipt['result']['line']);
        self::assertSame('<?php // scaffold', file_get_contents($this->root . '/' . self::SUBJECT));

        self::assertTrue($this->implement(self::BODY, 'start')['ok']);
        $finish = $this->implement('', 'finish');
        self::assertSame($receipt, $finish['diagnostic']);
        self::assertSame(self::BODY, file_get_contents($this->root . '/' . self::SUBJECT . '.milpa-part'));
        self::assertSame('<?php // scaffold', file_get_contents($this->root . '/' . self::SUBJECT));
    }

    public function testCommentsMoveTheLineButNotTheFindingAndOtherErrorsDiffer(): void
    {
        $original = $this->implement(self::BODY)['diagnostic'];
        $shift = $this->implement(str_replace('<?php', "<?php\n// A shifted location.", self::BODY))['diagnostic'];
        self::assertNotSame($original['judged_sha256'], $shift['judged_sha256']);
        self::assertSame($original['result']['line'] + 1, $shift['result']['line']);
        self::assertSame($original['result']['fingerprint'], $shift['result']['fingerprint']);
        $other = $this->implement(str_replace('class Greet', 'class Greet ?', self::BODY))['diagnostic'];
        self::assertNotSame($original['result']['fingerprint'], $other['result']['fingerprint']);
    }

    public function testParsingNeverExecutesTheBodyAndCompileFailuresStillUseLint(): void
    {
        $sentinel = $this->root . '/executed';
        $body = '<?php file_put_contents(' . var_export($sentinel, true) . ", 'executed');";
        self::assertNull(SyntaxFinding::inspect($body));
        self::assertNotNull(SyntaxFinding::inspect($body . ' class Broken ? {}'));
        self::assertFileDoesNotExist($sentinel);
        $duplicate = "<?php\ndeclare(strict_types=1);\nclass Greet { public function a() {} public function a() {} }";
        self::assertNull(SyntaxFinding::inspect($duplicate));
        $result = $this->implement($duplicate);
        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey('diagnostic', $result);
        self::assertStringContainsString('Cannot redeclare', $result['error']);
        self::assertSame('<?php // scaffold', file_get_contents($this->root . '/' . self::SUBJECT));
    }

    public function testAnEmbeddedDelimiterLocationDoesNotRenewInformationButNumericTokensDo(): void
    {
        $one = SyntaxFinding::inspect("<?php\nclass Greet {\nfunction broken(\n}");
        $shift = SyntaxFinding::inspect("<?php\n// Shift.\nclass Greet {\nfunction broken(\n}");
        $inline = SyntaxFinding::inspect('<?php class Greet { function broken( }');
        self::assertNotSame($one['message'], $shift['message']);
        self::assertSame($one['fingerprint'], $shift['fingerprint']);
        self::assertSame($one['fingerprint'], $inline['fingerprint']);
        self::assertNotSame(SyntaxFinding::inspect('<?php echo 123 456;')['fingerprint'], SyntaxFinding::inspect('<?php echo 123 457;')['fingerprint']);
    }

    public function testProcessFailureCannotPretendToBeAParserFinding(): void
    {
        mkdir($this->root . '/bin');
        $path = getenv('PATH');
        try {
            putenv('PATH=' . $this->root . '/bin:' . $path);
            foreach ([77, 255] as $exit) {
                file_put_contents($this->root . '/bin/php', "#!/bin/sh\necho 'syntax error from an unavailable process'\nexit " . $exit . "\n");
                chmod($this->root . '/bin/php', 0o700);
                $result = $this->implement("<?php\ndeclare(strict_types=1);\nclass Greet {}\n");
                self::assertFalse($result['ok']);
                self::assertArrayNotHasKey('diagnostic', $result);
                self::assertStringContainsString('exit ' . $exit, $result['error']);
                self::assertSame('<?php // scaffold', file_get_contents($this->root . '/' . self::SUBJECT));
                self::assertSame('syntax', $this->implement(self::BODY)['diagnostic']['phase'], 'The independent parser does not need a working subprocess.');
            }
        } finally {
            putenv('PATH=' . $path);
        }
    }
}
