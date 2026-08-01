<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Validators;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Validators\CapabilityGraphValidator;

final class CapabilityGraphValidatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-devtools-graph-' . uniqid();
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $manifest */
    private function write(string $name, array $manifest): string
    {
        $path = $this->dir . '/' . $name . '.json';
        file_put_contents($path, (string) json_encode($manifest));

        return $path;
    }

    public function testSatisfiableAcyclicGraphResolves(): void
    {
        $files = [
            $this->write('provider', [
                'name' => 'vendor/provider',
                'contracts' => ['provides' => ['Acme\\LoggerInterface']],
            ]),
            $this->write('consumer', [
                'name' => 'vendor/consumer',
                'contracts' => ['requires' => ['Acme\\LoggerInterface']],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertTrue($result->ok());
        $this->assertSame(2, $result->pluginCount);
        $this->assertSame([], $result->violations);
    }

    public function testUnmetHardRequireIsAViolation(): void
    {
        $files = [
            $this->write('consumer', [
                'name' => 'vendor/consumer',
                'contracts' => ['requires' => ['Acme\\Missing']],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('unmet require', $result->violations[0]);
    }

    public function testUnprovidedSuggestIsADegradationNotAFailure(): void
    {
        $files = [
            $this->write('consumer', [
                'name' => 'vendor/consumer',
                'contracts' => ['suggests' => ['Acme\\Optional']],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertTrue($result->ok());
        $this->assertCount(1, $result->degradations);
    }

    public function testDependencyCycleIsAViolation(): void
    {
        $files = [
            $this->write('a', [
                'name' => 'vendor/a',
                'dependencies' => ['plugins' => ['vendor/b' => '*']],
            ]),
            $this->write('b', [
                'name' => 'vendor/b',
                'dependencies' => ['plugins' => ['vendor/a' => '*']],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('dependency cycle', implode("\n", $result->violations));
    }
    // --- P17.3: el validador ya no tiene ley propia. Los tres casos que reportaba de más. ---

    public function testARecordIdentifiedOnlyByIdIsSeen(): void
    {
        // Antes sólo se leía `interface`, así que este proveedor era invisible y el consumidor
        // producía una violación que el motor nunca tuvo.
        $files = [
            $this->write('provider', [
                'name' => 'vendor/provider',
                'capabilities' => ['provides' => [['id' => 'crm.oauth.v1', 'contractVersion' => '1.0.0']]],
            ]),
            $this->write('consumer', [
                'name' => 'vendor/consumer',
                'capabilities' => ['requires' => [['id' => 'crm.oauth.v1']]],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertSame([], $result->violations);
    }

    public function testAnAlternativeInOneOfSatisfiesTheRequirement(): void
    {
        $files = [
            $this->write('provider', [
                'name' => 'vendor/ses',
                'capabilities' => ['provides' => [['id' => 'mail.ses', 'interface' => 'Acme\\MailerInterface']]],
            ]),
            $this->write('consumer', [
                'name' => 'vendor/consumer',
                'capabilities' => ['requires' => [[
                    'id' => 'mail.smtp',
                    'interface' => 'Acme\\SmtpInterface',
                    'oneOf' => ['mail.ses', 'mail.sendgrid'],
                ]]],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertSame([], $result->violations);
    }

    public function testTheCanonicalPhpFormAndTheLegacyBareFqcnAreOneIdentity(): void
    {
        $files = [
            $this->write('provider', [
                'name' => 'vendor/provider',
                'contracts' => ['provides' => ['\\Acme\\LoggerInterface']],
            ]),
            $this->write('consumer', [
                'name' => 'vendor/consumer',
                'capabilities' => ['requires' => [['id' => 'php:Acme\\LoggerInterface', 'interface' => 'php:Acme\\LoggerInterface']]],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertSame([], $result->violations);
    }

    public function testAGenuinelyUnprovidedCapabilityIsStillAViolation(): void
    {
        // El control negativo: aflojar la ley no puede volverla incapaz de fallar (ADR-0029).
        $files = [
            $this->write('consumer', [
                'name' => 'vendor/consumer',
                'capabilities' => ['requires' => [['id' => 'mail.smtp', 'oneOf' => ['mail.ses']]]],
            ]),
        ];

        $result = (new CapabilityGraphValidator())->validate($files);

        $this->assertCount(1, $result->violations);
        $this->assertStringContainsString('mail.smtp', $result->violations[0]);
    }
}
