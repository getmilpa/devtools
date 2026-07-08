<?php

declare(strict_types=1);

namespace Milpa\DevTools\Verify;

use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies a class follows the Milpa host-app controller convention: extends the host's
 * `Milpa\app\Providers\BaseController`, calls `parent::__construct()`, and every `#[Route]`-attributed
 * method has an uppercase HTTP verb, a leading-slash path, the `(Request $request, array $params = [])`
 * signature, an `HttpResponse` return type, no debug output, and no (verb, path) collision with a
 * sibling method.
 *
 * This convention — the exact FQCNs `Milpa\app\Providers\BaseController` / `HttpResponse` and the
 * `Milpa\Attributes\Route` attribute — belongs to the generated CODE's target, not to this package:
 * `coa:make controller` scaffolds a class that extends those host classes (see the `.stub` templates
 * under `Make/stubs/`), so this verifier closes the loop by checking the scaffolded output against the
 * same convention. Ported 1:1 from the original `scripts/verify-controller.php` (the reflection checks
 * only — the CLI's file-path→FQCN resolution and stdout formatting live in the thin CLI shim instead).
 */
final class ControllerVerifier implements VerifierInterface
{
    private const BASE_CONTROLLER = 'Milpa\\app\\Providers\\BaseController';
    private const ROUTE_ATTRIBUTE = 'Milpa\\Attributes\\Route';

    /** Reflects `$fqcn` and checks it against the host-app controller convention described above. */
    public function verify(string $fqcn): VerificationResult
    {
        if (!class_exists($fqcn)) {
            return new VerificationResult($fqcn, ["class not found: {$fqcn} — make sure the FQCN is correct and autoloadable"]);
        }

        $errors = [];
        $warnings = [];
        $routeCount = 0;

        $reflection = new ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        $source = $file !== false ? file_get_contents($file) : false;

        if ($source !== false && !str_contains($source, 'declare(strict_types=1)')) {
            $errors[] = 'Missing declare(strict_types=1) at top of file';
        }

        if (!$reflection->isSubclassOf(self::BASE_CONTROLLER)) {
            $errors[] = 'Class does not extend BaseController (' . self::BASE_CONTROLLER . ')';
        }

        if ($reflection->hasMethod('__construct') && $source !== false) {
            $ctor = $reflection->getMethod('__construct');
            $ctorBody = $this->slice($source, $ctor->getStartLine(), $ctor->getEndLine());
            if (!str_contains($ctorBody, 'parent::__construct(')) {
                $errors[] = 'Constructor does not call parent::__construct($container)';
            }
        }

        /** @var array<string, string> $seenRoutes "METHOD:path" => method name */
        $seenRoutes = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isStatic()) {
                continue;
            }

            $routeAttrs = $method->getAttributes(self::ROUTE_ATTRIBUTE);
            if ($routeAttrs === []) {
                continue;
            }

            $methodName = $method->getName();
            $routeCount++;

            foreach ($routeAttrs as $attrRef) {
                $args = $attrRef->getArguments();
                $path = $args['path'] ?? $args[0] ?? null;
                $httpMethod = $args['method'] ?? $args[1] ?? 'GET';

                if ($httpMethod !== strtoupper((string) $httpMethod)) {
                    $errors[] = "Method {$methodName}(): HTTP method '{$httpMethod}' must be uppercase (e.g. 'GET', 'POST', 'GET|POST')";
                }

                if ($path !== null && !str_starts_with((string) $path, '/')) {
                    $errors[] = "Method {$methodName}(): route path '{$path}' must start with '/'";
                }

                $key = strtoupper((string) $httpMethod) . ':' . $path;
                if (isset($seenRoutes[$key])) {
                    $errors[] = "Duplicate route [{$httpMethod} {$path}] defined in both {$seenRoutes[$key]}() and {$methodName}()";
                } else {
                    $seenRoutes[$key] = $methodName;
                }
            }

            $params = $method->getParameters();
            if (count($params) < 2) {
                $errors[] = "Method {$methodName}(): signature must be (Request \$request, array \$params = []) — missing second parameter 'array \$params = []'";
            } else {
                $p2 = $params[1];
                $p2Type = $p2->getType();
                if (!$p2Type || (string) $p2Type !== 'array') {
                    $errors[] = "Method {$methodName}(): second parameter must be typed 'array', found '" . ($p2Type ? (string) $p2Type : 'none') . "'";
                }
                if (!$p2->isDefaultValueAvailable() || $p2->getDefaultValue() !== []) {
                    $errors[] = "Method {$methodName}(): second parameter must have default value '= []' (array \$params = [])";
                }
            }

            if ($method->getReturnType() === null) {
                $warnings[] = "Method {$methodName}(): missing return type declaration (expected HttpResponse)";
            }

            if ($source !== false) {
                $body = $this->slice($source, $method->getStartLine(), $method->getEndLine());
                if (preg_match('/\b(echo|print|var_dump|var_export|print_r)\s*[(\s]/', $body) === 1) {
                    $errors[] = "Method {$methodName}(): contains debug output (echo/print/var_dump) — use \$this->logger instead";
                }
            }
        }

        return new VerificationResult($reflection->getShortName() . " — {$routeCount} route(s) registered", $errors, $warnings);
    }

    private function slice(string $source, int $startLine, int $endLine): string
    {
        $lines = explode(PHP_EOL, $source);

        return implode(PHP_EOL, array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
