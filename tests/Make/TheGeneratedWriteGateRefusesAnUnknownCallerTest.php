<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Make;

use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\CrudGenerator;
use Milpa\DevTools\Make\PlannedFile;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The generated write gate is EXECUTED, not read.
 *
 * `make:crud` used to publish `POST`, `PUT` and `DELETE` with `middleware: []`, and on a served
 * app an anonymous `POST` answered **201** with the row persisted (greenhouse `decisions/0459`,
 * `evidence/0991`). The generator now writes a gate and declares it on those three routes.
 *
 * Asserting the generated SOURCE contains a class would prove nothing about what it does when a
 * request arrives, so this writes the planned file to disk, loads it, and drives `process()`:
 * refused with nobody recognised, and passed through with an actor. The second half is the control
 * that matters — a middleware that refuses everything would pass a one-sided test and close the
 * route for good.
 *
 * @guards the generated write gate's verdict on both sides
 *
 * @fires  on every `make:crud` / `make:resource` run
 *
 * @refuses a request carrying no recognised actor
 *
 * @subject-in milpa/devtools
 */
final class TheGeneratedWriteGateRefusesAnUnknownCallerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-gate-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents(
            $this->root . '/composer.json',
            (string) json_encode(['autoload' => ['psr-4' => ['GateApp\\' => 'src/']]], JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testNobodyRecognisedIsRefusedAndAnActorGetsThrough(): void
    {
        $gate = $this->loadGeneratedGate();

        // The measured defect: this request is exactly the one that used to answer 201.
        $refused = $gate->process(new ServerRequest('POST', '/tasks'), $this->handlerThatWouldWrite());
        self::assertSame(401, $refused->getStatusCode());
        self::assertStringContainsString('application/json', $refused->getHeaderLine('Content-Type'));

        $body = json_decode((string) $refused->getBody(), true);
        self::assertIsArray($body);
        // The refusal names the fix — one that does not is one the reader cannot act on.
        self::assertStringContainsString('capabilities:enable milpa/auth', (string) ($body['how'] ?? ''));
        self::assertStringContainsString('TaskWritesGate', (string) ($body['how'] ?? ''));

        // THE CONTROL: a recognised caller goes through. Without this, a gate that refused
        // everything — including the app's own authenticated writes — would read as fixed.
        $recognised = new ServerRequest('POST', '/tasks');
        $allowed = $gate->process(
            $recognised->withAttribute('milpa.auth', $this->auth('rod')),
            $this->handlerThatWouldWrite(),
        );
        self::assertSame(201, $allowed->getStatusCode(), 'a recognised caller reaches the controller');
    }

    public function testAnAttributeOfAnUnexpectedShapeMeansNobodyAndNeverEverybody(): void
    {
        $gate = $this->loadGeneratedGate();
        $handler = $this->handlerThatWouldWrite();

        // Each of these is a `milpa.auth` an identity chain this gate was not generated against
        // could plausibly put there. Every one has to read as «nobody was recognised».
        $shapes = [
            'a string' => 'rod',
            'an array' => ['actor' => ['id' => 'rod']],
            'an object with no actor' => new \stdClass(),
            'an actor that is not an object' => $this->wrap('actor', 'rod'),
            'an actor with no id' => $this->wrap('actor', new \stdClass()),
            'an id that is not a string' => $this->wrap('actor', $this->wrap('id', 42)),
            'an id that is blank' => $this->auth('   '),
        ];

        foreach ($shapes as $label => $value) {
            $response = $gate->process(
                (new ServerRequest('POST', '/tasks'))->withAttribute('milpa.auth', $value),
                $handler,
            );
            self::assertSame(401, $response->getStatusCode(), "{$label} must read as nobody");
        }
    }

    /** Writes the planned gate to disk, loads it, and returns the live middleware. */
    private function loadGeneratedGate(): MiddlewareInterface
    {
        $result = (new CrudGenerator())->generate(new GenerationContext(
            plugin: 'BoardPlugin',
            name: 'Task',
            options: ['flavor' => 'runtime', 'fields' => 'title:string, done:bool'],
            root: $this->root,
        ));

        $planned = null;
        foreach ($result->files as $file) {
            \assert($file instanceof PlannedFile);
            if (basename($file->path) === 'TaskWritesGate.php') {
                $planned = $file;
            }
        }
        self::assertNotNull($planned, 'the generator plans a write gate');

        // A UNIQUE namespace per run: PHP cannot unload a class, and a second test loading the same
        // FQCN would silently exercise the FIRST run's bytes.
        $namespace = 'GateProbe' . bin2hex(random_bytes(5));
        $source = str_replace(
            'namespace GateApp\\Plugins\\BoardPlugin\\Http;',
            'namespace ' . $namespace . ';',
            $planned->contents,
        );
        self::assertStringContainsString('namespace ' . $namespace . ';', $source, 'the namespace was rewritten');

        $file = $this->root . '/gate.php';
        file_put_contents($file, $source);
        require $file;

        $fqcn = $namespace . '\\TaskWritesGate';
        self::assertTrue(class_exists($fqcn), 'the generated gate is loadable PHP');

        $gate = new $fqcn();
        self::assertInstanceOf(MiddlewareInterface::class, $gate);

        return $gate;
    }

    /** A handler that answers 201 — so «it got through» is observable, not inferred. */
    private function handlerThatWouldWrite(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(201, [], '{"id":1}');
            }
        };
    }

    /** The `milpa.auth` shape the identity chain declares: an object with an actor carrying an id. */
    private function auth(string $id): object
    {
        return $this->wrap('actor', $this->wrap('id', $id));
    }

    private function wrap(string $property, mixed $value): object
    {
        $object = new \stdClass();
        $object->{$property} = $value;

        return $object;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
