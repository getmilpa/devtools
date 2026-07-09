<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Verify;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for a bug where {@see \Milpa\DevTools\Verify\ControllerVerifier::verifyRuntime()}
 * unconditionally called `ReflectionClass::isSubclassOf('Milpa\app\Providers\BaseController')`. In
 * this package's own test process that never threw, because `tests/Fixtures/HostStubs.php` — loaded
 * unconditionally via composer.json's `autoload-dev.files` — always defines a stand-in
 * `Milpa\app\Providers\BaseController` class. But a genuine `milpa/runtime` host app, which is
 * exactly what the RUNTIME flavor targets, has NO such class anywhere on its classpath:
 * `ReflectionClass::isSubclassOf()` throws `ReflectionException` ("class ... does not exist") when
 * given the FQCN of a class that isn't loaded/loadable, rather than returning false. So the very
 * verify loop this flavor exists for would fatal on every real invocation — a bug the rest of this
 * package's suite structurally cannot see, because it always runs with `HostStubs.php` loaded.
 *
 * The fix (see `ControllerVerifier::verifyRuntime()`) guards the call: `class_exists(self::BASE_CONTROLLER)
 * && $reflection->isSubclassOf(...)` — short-circuiting on the `class_exists()` check so
 * `isSubclassOf()` is only ever called once the target class is confirmed loadable.
 *
 * To prove the fix holds in the one process shape that actually matters — no `BaseController` on the
 * classpath at all — this spins up a real `php` subprocess that deliberately does NOT load
 * `vendor/autoload.php` (which is what pulls in `HostStubs.php` via `autoload-dev.files`). Instead it
 * hand-requires only the exact source files `verifyRuntime()` needs — the `psr/http-message`
 * interfaces and this package's own `Make/Flavor.php` + `Verify/*.php` — mirroring the subprocess
 * approach `Milpa\Live\Tests\Support\MilpaDesignVendorInstallTest` uses to prove behavior that only
 * manifests with a specific, real classpath shape a same-process unit test cannot fake.
 *
 * Before the fix, this subprocess fatals with an uncaught `ReflectionException` (exit code 255,
 * "class ... Milpa\app\Providers\BaseController does not exist"). After the fix, it prints `PASS`
 * and exits 0.
 */
final class ControllerVerifierRuntimeSubprocessTest extends TestCase
{
    private ?string $probeDir = null;

    protected function tearDown(): void
    {
        if ($this->probeDir !== null && is_dir($this->probeDir)) {
            foreach (scandir($this->probeDir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($this->probeDir . '/' . $entry);
                }
            }
            rmdir($this->probeDir);
        }
    }

    public function testVerifyRuntimeDoesNotThrowAndPassesWhenTheLegacyBaseControllerClassIsAbsent(): void
    {
        $phpBinary = PHP_BINARY;
        if (!is_executable($phpBinary)) {
            self::markTestSkipped('php binary not invokable (PHP_BINARY not executable)');
        }

        $packageRoot = \dirname(__DIR__, 2); // tests/Verify -> tests -> package root

        $requiredFiles = [
            'psrMessage' => $packageRoot . '/vendor/psr/http-message/src/MessageInterface.php',
            'psrRequest' => $packageRoot . '/vendor/psr/http-message/src/RequestInterface.php',
            'psrServerRequest' => $packageRoot . '/vendor/psr/http-message/src/ServerRequestInterface.php',
            'psrResponse' => $packageRoot . '/vendor/psr/http-message/src/ResponseInterface.php',
            'flavor' => $packageRoot . '/src/Make/Flavor.php',
            'verifierInterface' => $packageRoot . '/src/Verify/VerifierInterface.php',
            'verificationResult' => $packageRoot . '/src/Verify/VerificationResult.php',
            'controllerVerifier' => $packageRoot . '/src/Verify/ControllerVerifier.php',
        ];
        foreach ($requiredFiles as $file) {
            if (!is_file($file)) {
                self::markTestSkipped("required source file missing, cannot build probe: {$file}");
            }
        }

        $this->probeDir = sys_get_temp_dir() . '/milpa-devtools-runtime-probe-' . bin2hex(random_bytes(6));
        mkdir($this->probeDir, 0o775, true);

        $probeTemplate = <<<'PHP'
            <?php

            declare(strict_types=1);

            // Deliberately does NOT require vendor/autoload.php — that is what pulls in
            // tests/Fixtures/HostStubs.php via composer.json's autoload-dev.files, which always
            // defines Milpa\app\Providers\BaseController. This probe hand-requires only the exact
            // files verifyRuntime() needs, so this process has NO BaseController anywhere on its
            // classpath — the real-world shape of a milpa/runtime host app.
            require '__PSR_MESSAGE__';
            require '__PSR_REQUEST__';
            require '__PSR_SERVER_REQUEST__';
            require '__PSR_RESPONSE__';

            require '__FLAVOR__';
            require '__VERIFIER_INTERFACE__';
            require '__VERIFICATION_RESULT__';
            require '__CONTROLLER_VERIFIER__';

            // Sanity check: the entire point of this probe is that this class is NOT defined here.
            // (Third arg `false` disables autoloading — there is no autoloader registered anyway.)
            if (class_exists('Milpa\\app\\Providers\\BaseController', false)) {
                fwrite(STDOUT, "FAIL: sanity check failed \xe2\x80\x94 BaseController is unexpectedly defined in this process\n");
                exit(1);
            }

            final class RuntimeSubprocessProbeController
            {
                public function index(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    throw new \RuntimeException('probe fixture only \xe2\x80\x94 never invoked');
                }
            }

            try {
                $verifier = new \Milpa\DevTools\Verify\ControllerVerifier(\Milpa\DevTools\Make\Flavor::Runtime);
                $result = $verifier->verify(RuntimeSubprocessProbeController::class);
            } catch (\Throwable $e) {
                fwrite(STDOUT, 'FAIL: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
                exit(1);
            }

            if (!$result->ok()) {
                fwrite(STDOUT, "FAIL: verifyRuntime reported errors:\n" . implode("\n", $result->errors) . "\n");
                exit(1);
            }

            fwrite(STDOUT, "PASS\n");
            exit(0);
            PHP;

        $probeScript = strtr($probeTemplate, [
            '__PSR_MESSAGE__' => $requiredFiles['psrMessage'],
            '__PSR_REQUEST__' => $requiredFiles['psrRequest'],
            '__PSR_SERVER_REQUEST__' => $requiredFiles['psrServerRequest'],
            '__PSR_RESPONSE__' => $requiredFiles['psrResponse'],
            '__FLAVOR__' => $requiredFiles['flavor'],
            '__VERIFIER_INTERFACE__' => $requiredFiles['verifierInterface'],
            '__VERIFICATION_RESULT__' => $requiredFiles['verificationResult'],
            '__CONTROLLER_VERIFIER__' => $requiredFiles['controllerVerifier'],
        ]);

        $probePath = $this->probeDir . '/probe.php';
        file_put_contents($probePath, $probeScript);

        $run = $this->runProcess([$phpBinary, $probePath]);

        self::assertSame(
            0,
            $run['exitCode'],
            "probe subprocess must exit 0 (before the fix it fatals with an uncaught ReflectionException instead):\n" . $run['output'],
        );
        self::assertStringContainsString(
            'PASS',
            $run['output'],
            "probe subprocess must report PASS \xe2\x80\x94 verifyRuntime() must not throw and must find zero errors "
            . "when Milpa\\app\\Providers\\BaseController is absent from the classpath:\n" . $run['output'],
        );
    }

    /**
     * @param list<string> $command
     *
     * @return array{exitCode: int, output: string}
     */
    private function runProcess(array $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($process, 'failed to start probe subprocess: ' . implode(' ', $command));

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exitCode' => $exitCode, 'output' => trim((string) $output)];
    }
}
