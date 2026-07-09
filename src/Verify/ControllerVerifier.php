<?php

declare(strict_types=1);

namespace Milpa\DevTools\Verify;

use Milpa\DevTools\Make\Flavor;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Verifies a class follows one of the two controller conventions {@see Flavor} names, chosen at
 * construction time (default {@see Flavor::Legacy}, matching this class's behavior before F1 —
 * every existing caller that builds `new ControllerVerifier()` with no argument is unaffected):
 *
 * - {@see Flavor::Legacy}: extends the host's `Milpa\app\Providers\BaseController`, calls
 *   `parent::__construct()`, and every `#[Route]`-attributed method has an uppercase HTTP verb, a
 *   leading-slash path, the `(Request $request, array $params = [])` signature, an `HttpResponse`
 *   return type, no debug output, and no (verb, path) collision with a sibling method. Ported 1:1
 *   from the original `scripts/verify-controller.php`.
 * - {@see Flavor::Runtime}: a plain class (NOT extending `BaseController`) with a public
 *   `index(ServerRequestInterface $request): ResponseInterface` method — no `#[Route]` attribute
 *   (that is legacy-only routing; runtime routes come from a `RouteProviderInterface` plugin, so a
 *   `#[Route]` here is flagged as an ignored no-op, not an error), no debug output.
 *
 * Every FQCN this class checks against (`Milpa\app\Providers\BaseController`, `Milpa\Attributes\Route`,
 * `Psr\Http\Message\ServerRequestInterface`, `Psr\Http\Message\ResponseInterface`) belongs to the
 * generated CODE's target, not to this package — `coa:make controller` scaffolds a class that targets
 * one of them (see the `.stub` templates under `Make/stubs/`), so this verifier closes the loop by
 * checking the scaffolded output against the same convention it was generated for.
 */
final class ControllerVerifier implements VerifierInterface
{
    private const BASE_CONTROLLER = 'Milpa\\app\\Providers\\BaseController';
    private const ROUTE_ATTRIBUTE = 'Milpa\\Attributes\\Route';
    private const SERVER_REQUEST_INTERFACE = 'Psr\\Http\\Message\\ServerRequestInterface';
    private const RESPONSE_INTERFACE = 'Psr\\Http\\Message\\ResponseInterface';

    public function __construct(private readonly Flavor $flavor = Flavor::Legacy)
    {
    }

    /** Reflects `$fqcn` and checks it against the constructor-selected {@see Flavor}'s convention. */
    public function verify(string $fqcn): VerificationResult
    {
        if (!class_exists($fqcn)) {
            return new VerificationResult($fqcn, ["class not found: {$fqcn} — make sure the FQCN is correct and autoloadable"]);
        }

        return $this->flavor === Flavor::Runtime ? $this->verifyRuntime($fqcn) : $this->verifyLegacy($fqcn);
    }

    private function verifyLegacy(string $fqcn): VerificationResult
    {
        $errors = [];
        $warnings = [];
        $routeCount = 0;

        $reflection = new ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        $source = $file !== false ? file_get_contents($file) : false;

        if ($source !== false && !str_contains($source, 'declare(strict_types=1)')) {
            $errors[] = 'Missing declare(strict_types=1) at top of file';
        }

        if (!class_exists(self::BASE_CONTROLLER) || !$reflection->isSubclassOf(self::BASE_CONTROLLER)) {
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

    /**
     * Checks the RUNTIME convention: a plain class (no `BaseController`) with a public
     * `index(ServerRequestInterface $request): ResponseInterface` method, no debug output. Unlike
     * {@see verifyLegacy()} there is no `#[Route]`/path/verb bookkeeping to check — runtime routing
     * lives entirely in a `RouteProviderInterface` plugin, outside this class.
     */
    private function verifyRuntime(string $fqcn): VerificationResult
    {
        $errors = [];
        $warnings = [];

        $reflection = new ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        $source = $file !== false ? file_get_contents($file) : false;

        if ($source !== false && !str_contains($source, 'declare(strict_types=1)')) {
            $errors[] = 'Missing declare(strict_types=1) at top of file';
        }

        if (class_exists(self::BASE_CONTROLLER) && $reflection->isSubclassOf(self::BASE_CONTROLLER)) {
            $errors[] = 'Runtime controllers must be plain classes — found extends ' . self::BASE_CONTROLLER
                . ' (that is the legacy convention; pass --flavor=legacy, or drop the parent class)';
        }

        if (!$reflection->hasMethod('index')) {
            $errors[] = 'Missing public method index(ServerRequestInterface $request): ResponseInterface';

            return new VerificationResult($reflection->getShortName() . ' — runtime controller', $errors, $warnings);
        }

        $method = $reflection->getMethod('index');
        if (!$method->isPublic()) {
            $errors[] = 'index() must be public';
        }

        if ($method->getAttributes(self::ROUTE_ATTRIBUTE) !== []) {
            $warnings[] = 'index(): carries a #[Route] attribute, which is ignored — runtime controllers are '
                . 'wired via a RouteProviderInterface plugin, not attribute routing';
        }

        $params = $method->getParameters();
        if ($params === []) {
            $errors[] = 'index(): signature must accept a ' . self::SERVER_REQUEST_INTERFACE . ' parameter';
        } else {
            $p1Type = $params[0]->getType();
            $p1TypeName = $p1Type instanceof ReflectionNamedType ? $p1Type->getName() : null;
            if ($p1TypeName === null || !$this->typeMatches($p1TypeName, self::SERVER_REQUEST_INTERFACE)) {
                $errors[] = "index(): first parameter must be typed " . self::SERVER_REQUEST_INTERFACE
                    . " (found '" . ($p1TypeName ?? 'none') . "')";
            }
        }

        $returnType = $method->getReturnType();
        $returnTypeName = $returnType instanceof ReflectionNamedType ? $returnType->getName() : null;
        if ($returnTypeName === null || !$this->typeMatches($returnTypeName, self::RESPONSE_INTERFACE)) {
            $errors[] = "index(): return type must implement " . self::RESPONSE_INTERFACE
                . " (found '" . ($returnTypeName ?? 'none') . "')";
        }

        if ($source !== false) {
            $body = $this->slice($source, $method->getStartLine(), $method->getEndLine());
            if (preg_match('/\b(echo|print|var_dump|var_export|print_r)\s*[(\s]/', $body) === 1) {
                $errors[] = 'index(): contains debug output (echo/print/var_dump) — use a logger instead';
            }
        }

        return new VerificationResult($reflection->getShortName() . ' — runtime controller', $errors, $warnings);
    }

    /**
     * Whether `$typeName` IS `$target`, or is-a `$target` (checked via `is_a()` — but only once
     * `$typeName` is confirmed loadable, so a `$target` interface that happens not to be installed
     * on the verifying host — e.g. `psr/http-message` missing — never triggers an autoload warning;
     * it just falls back to the exact-name comparison, which is what the runtime stub itself
     * produces).
     */
    private function typeMatches(string $typeName, string $target): bool
    {
        if ($typeName === $target) {
            return true;
        }

        if (!class_exists($typeName) && !interface_exists($typeName)) {
            return false;
        }

        return is_a($typeName, $target, true);
    }

    private function slice(string $source, int $startLine, int $endLine): string
    {
        $lines = explode(PHP_EOL, $source);

        return implode(PHP_EOL, array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
