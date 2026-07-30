<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Validators;

use Milpa\Attributes\PluginMetadata;
use Milpa\DevTools\Validators\MetadataParityValidator;
use PHPUnit\Framework\TestCase;

#[PluginMetadata(version: '1.0.0', author: 'Acme', site: 'https://teamx.agency', name: 'ParityFixture', type: 'Service')]
class ParityFixturePlugin
{
}

/** Fixture carrying a rich `provides` record — used to prove key order inside a record is not divergence. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://teamx.agency',
    name: 'ParityRecordFixture',
    type: 'Service',
    provides: [['interface' => 'Some\\Iface', 'id' => 'x.y.v1', 'contractVersion' => '1.0.0', 'service' => 'Some\\Svc']],
)]
class ParityRecordFixturePlugin
{
}

/**
 * The D5 watchdog: a plugin whose milpa.json diverges from its attribute on a
 * GRAPH field fails parity; the documented cosmetic `site` gap never does.
 */
final class MetadataParityValidatorTest extends TestCase
{
    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifest = sys_get_temp_dir() . '/milpa-parity-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides Merged over the canonical fixture defaults. A key
     *                                        passed explicitly as `null` (e.g. `'contracts' => null`) DROPS that default entirely
     *                                        instead of writing a literal `null` — this lets the capability-record tests swap the
     *                                        legacy `contracts` block for a `capabilities` block without emitting both.
     */
    private function writeManifest(array $overrides = []): void
    {
        $data = array_filter($overrides + [
            'name' => 'milpa/parityfixture',
            'displayName' => 'ParityFixture',
            'description' => '',
            'version' => '1.0.0',
            'type' => 'Service',
            'license' => 'MIT',
            'authors' => [['name' => 'Acme', 'email' => '']],
            'milpa' => ['min-version' => '2.0.0', 'php-version' => '>=8.2'],
            'contracts' => ['provides' => [], 'requires' => [], 'suggests' => []],
            'dependencies' => ['plugins' => new \stdClass(), 'composer' => new \stdClass()],
            'entrypoint' => 'ParityFixturePlugin.php',
            'namespace' => 'Milpa\\DevTools\\Tests\\Validators',
            'migrations' => ['directory' => 'Migrations'],
            'env-vars' => [],
        ], static fn (mixed $v): bool => $v !== null);

        file_put_contents($this->manifest, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** A manifest matching the attribute on every graph field passes (site is excluded by design). */
    public function testMatchingGraphFieldsPass(): void
    {
        $this->writeManifest();

        $result = (new MetadataParityValidator())->validate($this->manifest, ParityFixturePlugin::class);

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->divergent);
    }

    /** A version mismatch is a graph divergence and fails parity naming the field. */
    public function testVersionDivergenceFails(): void
    {
        $this->writeManifest(['version' => '9.9.9']);

        $result = (new MetadataParityValidator())->validate($this->manifest, ParityFixturePlugin::class);

        $this->assertFalse($result->ok());
        $this->assertContains('version', $result->divergent);
    }

    /** An unreadable manifest degrades to a named failure, never a crash. */
    public function testAnUnreadableManifestFailsNamingTheManifest(): void
    {
        file_put_contents($this->manifest, '{not json');

        $result = (new MetadataParityValidator())->validate($this->manifest, ParityFixturePlugin::class);

        $this->assertFalse($result->ok());
        $this->assertNotEmpty($result->divergent);
        $this->assertStringStartsWith('manifest:', $result->divergent[0]);
    }

    /** A non-version graph field (author) divergence is detected and named. */
    public function testAuthorDivergenceFails(): void
    {
        $this->writeManifest(['authors' => [['name' => 'SomeoneElse', 'email' => '']]]);

        $result = (new MetadataParityValidator())->validate($this->manifest, ParityFixturePlugin::class);

        $this->assertFalse($result->ok());
        $this->assertContains('author', $result->divergent);
    }

    /** Key order inside a capability record is presentation, not divergence. */
    public function testReorderedRecordKeysDoNotDiverge(): void
    {
        $this->writeManifest([
            'contracts' => null,
            'capabilities' => [
                'provides' => [[
                    'id' => 'x.y.v1',
                    'interface' => 'Some\\Iface',
                    'contractVersion' => '1.0.0',
                    'service' => 'Some\\Svc',
                ]],
                'requires' => [],
                'suggests' => [],
            ],
        ]);

        $result = (new MetadataParityValidator())->validate($this->manifest, ParityRecordFixturePlugin::class);

        $this->assertNotContains('provides', $result->divergent);
    }

    /** A genuinely different record value still diverges after normalization. */
    public function testDifferentRecordValueStillDiverges(): void
    {
        $this->writeManifest([
            'contracts' => null,
            'capabilities' => [
                'provides' => [[
                    'id' => 'x.OTHER.v1',
                    'interface' => 'Some\\Iface',
                    'contractVersion' => '1.0.0',
                    'service' => 'Some\\Svc',
                ]],
                'requires' => [],
                'suggests' => [],
            ],
        ]);

        $result = (new MetadataParityValidator())->validate($this->manifest, ParityRecordFixturePlugin::class);

        $this->assertContains('provides', $result->divergent);
    }
}
