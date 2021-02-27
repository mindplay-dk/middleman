<?php

use Interop\Container\ContainerInterface;
use Interop\Http\Server\MiddlewareInterface as LegacyMiddlewareInterface;
use mindplay\middleman\ContainerResolver;
use mindplay\middleman\Dispatcher;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandlerInterface;
use function mindplay\testies\{ configure, test, run, ok, eq, expect };

require dirname(__DIR__) . '/vendor/autoload.php';

configure()->enableCodeCoverage(__DIR__ . '/build/clover.xml', dirname(__DIR__) . '/src');

/**
 * @return ServerRequestInterface
 */
function mock_server_request()
{
    return (new Psr17Factory)->createServerRequest("GET", "http://localhost");
}

/**
 * @return ResponseInterface
 */
function mock_response()
{
    return (new Psr17Factory)->createResponse(200);
}

test(
    'Throws for empty middleware stack',
    function () {
        expect(
            'InvalidArgumentException',
            'should throw for empty middleware stack',
            function () {
                new Dispatcher([]);
            }
        );
    }
);

test(
    'Throws for unresolved request',
    function () {
        $dispatcher = new Dispatcher([
            function (ServerRequestInterface $request, $next) {
                return $next($request);
            }
        ]);

        expect(
            'LogicException',
            'should throw for exhausted middleware stack',
            function () use ($dispatcher) {
                $dispatcher->handle(mock_server_request());
            }
        );
    }
);

test(
    'Can dispatch callable as middleware',
    function () {
        $called = false;

        $received = false;

        $request = mock_server_request();

        $dispatcher = new Dispatcher([
            function (ServerRequestInterface $received_request, $next) use ($request, &$called, &$received) {
                $called = true;

                $received = $received_request === $request;

                return mock_response();
            }
        ]);

        $returned = $dispatcher->handle($request);

        ok($called, 'the middleware was dispatched');
        ok($returned instanceof ResponseInterface, 'it returns the response');
    }
);

test(
    'Can dispatch middleware stack',
    function () {
        $called_one = 0;
        $called_two = 0;
        $order = 1;

        $dispatcher = new Dispatcher([
            function (ServerRequestInterface $request, $next) use (&$called_one, &$order) {
                $called_one = $order++;

                return $next($request);
            },
            function (ServerRequestInterface $request, $next) use (&$called_two, &$order) {
                $called_two = $order++;

                return mock_response();
            }
        ]);

        $returned = $dispatcher->handle(mock_server_request());

        ok($called_one === 1, 'the first middleware was dispatched');
        ok($called_two === 2, 'the second middleware was dispatched');

        ok($returned instanceof ResponseInterface, 'it returns the response');
    }
);

test(
    'Can resolve middleware',
    function () {
        $resolved = [
            'one' => 0,
            'two' => 0,
        ];

        $called = [
            'one' => 0,
            'two' => 0,
        ];

        $dispatcher = new Dispatcher(
            ['one', 'two', function () {
                return mock_response();
            }],
            function ($init) use (&$called, &$resolved) {
                if (!is_string($init)) {
                    return $init;
                }

                $resolved[$init] += 1;

                return function ($request, $next) use (&$called, $init) {
                    $called[$init] += 1;

                    return $next($request);
                };
            }
        );

        $dispatcher->handle(mock_server_request());

        eq($called['one'], 1, 'the first middleware was dispatched (once)');
        eq($called['two'], 1, 'the second middleware was dispatched (once)');

        $returned = $dispatcher->handle(mock_server_request());

        ok($returned instanceof ResponseInterface, 'it returned the response');

        // can dispatch the same middleware stack more than once:

        eq($called['one'], 2, 'the first middleware was dispatched (twice)');
        eq($called['two'], 2, 'the second middleware was dispatched (twice)');

        ok($returned instanceof ResponseInterface, 'it returned the response');

        // initialization occurs only once:

        eq($resolved['one'], 2, 'the first middleware was resolved (twice)');
        eq($resolved['two'], 2, 'the second middleware was resolved (twice)');
    }
);

test(
    'throws exception on unexpected/missing result',
    function () {
        $dispatcher = new Dispatcher([
            function () {
                return 123;
            }
        ]);

        $request = mock_server_request();

        expect(
            'LogicException',
            'should throw on wrong return-type',
            function () use ($dispatcher, $request) {
                $dispatcher->handle($request);
            }
        );
    }
);

class MockContainer implements ContainerInterface
{
    public $contents = [];

    public function get($id)
    {
        return $this->contents[$id];
    }

    public function has($id)
    {
        return isset($this->contents[$id]);
    }
}

test(
    'can integrate with PSR-11 container',
    function () {
        $container = new MockContainer();

        $called_indirect = false;
        $called_direct = false;

        $container->contents['foo'] = function (ServerRequestInterface $request, $next) use (&$called_indirect) {
            $called_indirect = true;

            return $next($request);
        };

        $resolver = new ContainerResolver($container);

        $dispatcher = new Dispatcher(
            [
                'foo', // to be resolved by $container via InteropResolver
                function (ServerRequestInterface $request, $next) use (&$called_direct) {
                    $called_direct = true;

                    return mock_response();
                }
            ],
            $resolver
        );

        $dispatcher->handle(mock_server_request());

        ok($called_indirect, 'middleware gets resolved via DI container and invoked');
        ok($called_direct, 'other middleware gets invoked directly');

        // test with un-resolvable component name:

        $dispatcher = new Dispatcher(['bar'], $resolver);

        expect(
            'RuntimeException',
            'should throw for middleware that cannot be resolved',
            function () use ($dispatcher) {
                $dispatcher->handle(mock_server_request());
            }
        );
    }
);

class PsrMockContainer implements PsrContainerInterface
{
    public function get($id) {}
    public function has($id) {}
}

test(
    'can integrate with legacy container-interop',
    function () {
        $container = new PsrMockContainer();

        $resolver = new ContainerResolver($container);

        ok(true, "ContainerResolver constructor accepts a PSR container argument");
    }
);

test(
    'can dispatch nested middleware stacks',
    function () {
        $result = [];

        $dispatcher = new Dispatcher(
            [
                function (ServerRequestInterface $request, $next) use (&$result) {
                    $result[] = 1;

                    return $next($request);
                },
                new Dispatcher([
                    function (ServerRequestInterface $request, $next) use (&$result) {
                        $result[] = 2;

                        return $next($request);
                    },
                    function (ServerRequestInterface $request, $next) use (&$result) {
                        $result[] = 3;

                        return $next($request);
                    }
                ]),
                function (ServerRequestInterface $request, $next) use (&$result) {
                    $result[] = 4;

                    return mock_response();
                }
            ]
        );

        $response = $dispatcher->handle(mock_server_request());

        eq($result, [1, 2, 3, 4], "executes nested middleware components in order");

        ok($response instanceof ResponseInterface, "it returns the response");
    }
);

class InvokableMiddleware
{
    private $result;

    public function __construct($result = null)
    {
        $this->result = $result;
    }

    public function __invoke(ServerRequestInterface $request, $delegate)
    {
        return $this->result ?: $delegate($request);
    }
}

test(
    'can dispatch middlewares implementing __invoke()',
    function () {
        $dispatcher = new Dispatcher([
            new InvokableMiddleware(),
            new InvokableMiddleware(mock_response())
        ]);

        ok($dispatcher->handle(mock_server_request()) instanceof ResponseInterface);
    }
);

class LegacyServerMiddleware implements LegacyMiddlewareInterface
{
    private $result;

    public function __construct($result = null)
    {
        $this->result = $result;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->result ?: $handler->handle($request);
    }
}

test(
    'can dispatch legacy PSR-15 server-middleware',
    function () {
        $dispatcher = new Dispatcher([
            new LegacyServerMiddleware(),
            new LegacyServerMiddleware(mock_response())
        ]);

        ok($dispatcher->handle(mock_server_request()) instanceof ResponseInterface);
    }
);

class PSRServerMiddleware implements PsrMiddlewareInterface
{
    private $result;

    public function __construct($result = null)
    {
        $this->result = $result;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->result ?: $handler->handle($request);
    }
}

test(
    'can dispatch final PSR-15 server-middleware',
    function () {
        $dispatcher = new Dispatcher([
            new PSRServerMiddleware(),
            new PSRServerMiddleware(mock_response())
        ]);

        ok($dispatcher->handle(mock_server_request()) instanceof ResponseInterface);
    }
);

exit(run());
