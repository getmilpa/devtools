<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\DevTools\Operations\ImplementationBody;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/** The actual landing gate must restore rejected proposals before attributing their analysis. */
final class StaticAnalysisReceiptTest extends TestCase
{
    private string $root;
    private const SUBJECT = 'src/Plugins/Demo/Services/Sample.php';
    private const SCAFFOLD = "<?php\ndeclare(strict_types=1);\nnamespace App\\Plugins\\Demo\\Services;\nfinal class Sample {}\n";
    private const BODY = "<?php\ndeclare(strict_types=1);\nnamespace Wrong;\nfinal class Sample { public function run(): void { unavailable_probe_0786(); } }\n";

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-static-receipt-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/' . dirname(self::SUBJECT), 0o700, true);
        file_put_contents($this->root . '/' . self::SUBJECT, self::SCAFFOLD);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, mixed> */
    private function call(ImplementHandler $handler, string $body = self::BODY): array
    {
        return $handler->handle(['plugin' => 'Demo', 'class' => 'Sample', 'content' => $body]);
    }

    /** A trusted analyzer seam that emits one complete report, optionally damaging its subject. */
    private function analyzer(int $exit = 1, string $mutation = '', string $output = ''): ImplementHandler
    {
        $script = $this->root . '/analyze.php';
        file_put_contents($script, '<?php $file = $argv[1]; ' . $mutation . "\n"
            . ($output !== '' ? $output : <<<'PHP'
            echo json_encode(['totals' => ['errors' => 0, 'file_errors' => 1], 'files' => [$file => [
                'errors' => 1, 'messages' => [['message' => 'Unknown function.', 'identifier' => 'function.notFound', 'line' => 4, 'ignorable' => true]],
            ]], 'errors' => []]);
            fwrite(STDERR, "Note: using configuration.\n");
            PHP)
            . '; exit(' . $exit . ');');
        return new ImplementHandler(new RootResolver($this->root), 'php ' . escapeshellarg($script));
    }

    public function testInstalledPhpStanProducesAttributedFindingsAndCommentChangesDoNotRenewThem(): void
    {
        symlink(dirname(__DIR__, 2) . '/vendor', $this->root . '/vendor');
        $handler = new ImplementHandler(new RootResolver($this->root));
        $first = $this->call($handler);
        self::assertFalse($first['ok']);
        self::assertArrayHasKey('diagnostic', $first, $first['error']);
        $receipt = $first['diagnostic'];
        self::assertSame('static-analysis', $receipt['phase']);
        self::assertSame(self::SUBJECT, $receipt['subject']);
        self::assertSame(hash('sha256', self::BODY), $receipt['submitted_sha256']);
        self::assertSame(hash('sha256', ImplementationBody::normalize(self::BODY, self::SUBJECT)), $receipt['judged_sha256']);
        self::assertTrue($receipt['stable_subject']);
        self::assertTrue($receipt['rolled_back']);
        self::assertSame(hash('sha256', self::SCAFFOLD), $receipt['restored_sha256']);
        self::assertSame(1, $receipt['result']['errors']);
        self::assertSame('function.notFound', $receipt['result']['findings'][0]['identifier']);
        $comment = $this->call($handler, str_replace('final class', "// A comment changes lines, not the finding.\nfinal class", self::BODY));
        self::assertArrayHasKey('diagnostic', $comment, $comment['error']);
        self::assertNotSame($receipt['judged_sha256'], $comment['diagnostic']['judged_sha256']);
        self::assertNotSame($receipt['result']['findings'][0]['line'], $comment['diagnostic']['result']['findings'][0]['line']);
        self::assertSame($receipt['result']['fingerprint'], $comment['diagnostic']['result']['fingerprint']);
        self::assertSame(self::SCAFFOLD, file_get_contents($this->root . '/' . self::SUBJECT));
    }

    public function testInlineAndFinishShareTheSameReceiptAndRollback(): void
    {
        $handler = $this->analyzer();
        $inline = $this->call($handler);
        self::assertArrayHasKey('diagnostic', $inline, $inline['error']);
        $start = $handler->handle(['plugin' => 'Demo', 'class' => 'Sample', 'mode' => 'start', 'content' => self::BODY]);
        self::assertTrue($start['ok']);
        $finish = $handler->handle(['plugin' => 'Demo', 'class' => 'Sample', 'mode' => 'finish']);
        self::assertSame($inline['diagnostic'], $finish['diagnostic']);
        self::assertSame(self::SCAFFOLD, file_get_contents($this->root . '/' . self::SUBJECT));
    }

    public function testInfrastructureMalformedAndUnstableReportsRestoreWithoutDiagnosticCredit(): void
    {
        foreach ([2, 77, 124] as $exit) {
            $result = $this->call($this->analyzer($exit));
            self::assertFalse($result['ok']);
            self::assertArrayNotHasKey('diagnostic', $result);
            self::assertSame(self::SCAFFOLD, file_get_contents($this->root . '/' . self::SUBJECT));
        }
        foreach (['echo "malformed";', 'echo "{}";', 'fwrite(STDERR, "infrastructure failure");'] as $output) {
            $result = $this->call($this->analyzer(output: $output));
            self::assertArrayNotHasKey('diagnostic', $result);
            self::assertSame(self::SCAFFOLD, file_get_contents($this->root . '/' . self::SUBJECT));
        }
        $result = $this->call($this->analyzer(mutation: 'file_put_contents($file, "changed during analysis");'));
        self::assertArrayNotHasKey('diagnostic', $result);
        self::assertSame(self::SCAFFOLD, file_get_contents($this->root . '/' . self::SUBJECT));
    }

    public function testAnObstructedRestoreIsExplicitAndCannotEmitAReceipt(): void
    {
        $result = $this->call($this->analyzer(mutation: 'unlink($file); mkdir($file); file_put_contents($file . "/obstruction", "preserve");'));
        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey('diagnostic', $result);
        self::assertStringContainsString('could not be restored', $result['error']);
        self::assertDirectoryExists($this->root . '/' . self::SUBJECT);
        self::assertSame('preserve', file_get_contents($this->root . '/' . self::SUBJECT . '/obstruction'));
    }
}
