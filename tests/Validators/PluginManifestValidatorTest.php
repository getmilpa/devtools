<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Validators;

use PHPUnit\Framework\TestCase;
use Milpa\DevTools\Validators\PluginManifestValidator;

final class PluginManifestValidatorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-devtools-manifest-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function write(array $manifest): string
    {
        file_put_contents($this->path, (string) json_encode($manifest));

        return $this->path;
    }

    public function testValidManifestPasses(): void
    {
        $path = $this->write([
            'name' => 'vendor/plugin',
            'version' => '1.0.0',
            'type' => 'Mixed',
            'namespace' => 'Milpa\\Plugins\\Plugin',
            'entrypoint' => 'Plugin.php',
        ]);

        $result = (new PluginManifestValidator())->validate($path);

        $this->assertTrue($result->ok(), implode("\n", $result->errors));
    }

    public function testMissingFileIsAnError(): void
    {
        $result = (new PluginManifestValidator())->validate($this->path);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('file not found', $result->errors[0]);
    }

    public function testMissingRequiredFieldsAreReported(): void
    {
        $path = $this->write(['name' => 'vendor/plugin']);

        $result = (new PluginManifestValidator())->validate($path);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('missing or empty required field: version', implode("\n", $result->errors));
    }

    public function testNonSemverVersionIsReported(): void
    {
        $path = $this->write([
            'name' => 'vendor/plugin',
            'version' => 'v1',
            'type' => 'Mixed',
            'namespace' => 'Milpa\\Plugins\\Plugin',
            'entrypoint' => 'Plugin.php',
        ]);

        $result = (new PluginManifestValidator())->validate($path);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('version must be semver', implode("\n", $result->errors));
    }

    public function testCapabilitiesProvidesRecordShapeIsValidated(): void
    {
        $path = $this->write([
            'name' => 'vendor/plugin',
            'version' => '1.0.0',
            'type' => 'Mixed',
            'namespace' => 'Milpa\\Plugins\\Plugin',
            'entrypoint' => 'Plugin.php',
            'capabilities' => ['provides' => [['id' => 'x']]],
        ]);

        $result = (new PluginManifestValidator())->validate($path);

        $this->assertFalse($result->ok());
        $errors = implode("\n", $result->errors);
        $this->assertStringContainsString("missing required key 'interface'", $errors);
        $this->assertStringContainsString("missing required key 'service'", $errors);
    }
}
