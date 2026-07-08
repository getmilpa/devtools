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
}
