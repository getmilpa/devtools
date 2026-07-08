<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Validators;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Tests\Fixtures\SampleContractImplementation;
use Milpa\DevTools\Tests\Fixtures\SampleContractInterface;
use Milpa\DevTools\Tests\Fixtures\SampleContractNonImplementation;
use Milpa\DevTools\Validators\ProviderImplementsValidator;

final class ProviderImplementsValidatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-devtools-provider-' . uniqid();
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
    private function write(array $manifest): string
    {
        $path = $this->dir . '/manifest.json';
        file_put_contents($path, (string) json_encode($manifest));

        return $path;
    }

    public function testRealImplementationResolves(): void
    {
        $file = $this->write([
            'capabilities' => ['provides' => [[
                'interface' => SampleContractInterface::class,
                'service' => SampleContractImplementation::class,
            ]]],
        ]);

        $result = (new ProviderImplementsValidator())->validate([$file]);

        $this->assertTrue($result->ok());
        $this->assertSame(1, $result->checked);
    }

    public function testServiceNotImplementingInterfaceIsAViolation(): void
    {
        $file = $this->write([
            'capabilities' => ['provides' => [[
                'interface' => SampleContractInterface::class,
                'service' => SampleContractNonImplementation::class,
            ]]],
        ]);

        $result = (new ProviderImplementsValidator())->validate([$file]);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('does not implement', $result->violations[0]);
    }

    public function testUnautoloadableInterfaceIsAViolation(): void
    {
        $file = $this->write([
            'contracts' => ['provides' => ['Milpa\\DevTools\\Tests\\Fixtures\\DoesNotExist']],
        ]);

        $result = (new ProviderImplementsValidator())->validate([$file]);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('does not autoload', $result->violations[0]);
    }
}
