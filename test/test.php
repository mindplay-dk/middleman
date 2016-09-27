<?php

use Interop\Container\ContainerInterface;
use Interop\Http\Middleware\DelegateInterface;
use Interop\Http\Middleware\MiddlewareInterface;
use Interop\Http\Middleware\ServerMiddlewareInterface;
use mindplay\middleman\Dispatcher;
use mindplay\middleman\ContainerResolver;
use Mockery\MockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

configure()->enableCodeCoverage(__DIR__ . '/build/clover.xml', dirname(__DIR__) . '/src');

/**
 * @return MockInterface|RequestInterface
 */
function mock_request() {
    return Mockery::mock('Psr\Http\Message\RequestInterface');
}

/**
 * @return MockInterface|RequestInterface
 */
function mock_server_request() {
    return Mockery::mock('Psr\Http\Message\ServerRequestInterface');
}

/**
 * @return MockInterface|ResponseInterface
 */
function mock_response() {
    return Mockery::mock('Psr\Http\Message\ResponseInterface');
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
            function (RequestInterface $request, $next) {
                return $next($request);
            }
        ]);

        expect(
            'LogicException',
            'should throw for exhausted middleware stack',
            function () use ($dispatcher) {
                $dispatcher->dispatch(mock_request());
            }
        );
    }
);

test(
    'Can dispatch callable as middleware',
    function () {
        $called = false;

        $received = false;

        $request = mock_request();

        $dispatcher = new Dispatcher([
            function (RequestInterface $received_request, $next) use ($request, &$called, &$received) {
                $called = true;

                $received = $received_request === $request;

                return mock_response();
            }
        ]);

        $returned = $dispatcher->dispatch($request);

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
            function (RequestInterface $request, $next) use (&$called_one, &$order) {
                $called_one = $order++;

                return $next($request);
            },
            function (RequestInterface $request, $next) use (&$called_two, &$order) {
                $called_two = $order++;

                return mock_response();
            }
        ]);

        $returned = $dispatcher->dispatch(mock_request());

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
            ['one', 'two', function () { return mock_response(); }],
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

        $dispatcher->dispatch(mock_request());

        eq($called['one'], 1, 'the first middleware was dispatched (once)');
        eq($called['two'], 1, 'the second middleware was dispatched (once)');

        $returned = $dispatcher->dispatch(mock_request());

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

        $request = mock_request();

        expect(
            'LogicException',
            'should throw on wrong return-type',
            function () use ($dispatcher, $request) {
                $dispatcher->dispatch($request);
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
    'can integrate with container-interop',
    function () {
        $container = new MockContainer();

        $called_indirect = false;
        $called_direct = false;

        $container->contents['foo'] = function (RequestInterface $request, $next) use (&$called_indirect) {
            $called_indirect = true;

            return $next($request);
        };

        $resolver = new ContainerResolver($container);

        $dispatcher = new Dispatcher(
            [
                'foo', // to be resolved by $container via InteropResolver
                function (RequestInterface $request, $next) use (&$called_direct) {
                    $called_direct = true;

                    return mock_response();
                }
            ],
            $resolver
        );

        $dispatcher->dispatch(mock_request());

        ok($called_indirect, 'middleware gets resolved via DI container and invoked');
        ok($called_direct, 'other middleware gets invoked directly');

        // test with un-resolvable component name:

        $dispatcher = new Dispatcher(['bar'], $resolver);

        expect(
            'RuntimeException',
            'should throw for middleware that cannot be resolved',
            function () use ($dispatcher) {
                $dispatcher->dispatch(mock_request());
            }
        );
    }
);

test(
    'can dispatch nested middleware stacks',
    function () {
        $result = [];

        $dispatcher = new Dispatcher(
            [
                function (RequestInterface $request, $next) use (&$result) {
                    $result[] = 1;
                    
                    return $next($request);
                },
                new Dispatcher([
                    function (RequestInterface $request, $next) use (&$result) {
                        $result[] = 2;

                        return $next($request);
                    },
                    function (RequestInterface $request, $next) use (&$result) {
                        $result[] = 3;

                        return $next($request);
                    }
                ]),
                function (RequestInterface $request, $next) use (&$result) {
                    $result[] = 4;

                    return mock_response();
                }
            ]
        );
        
        $response = $dispatcher->dispatch(mock_request());
        
        eq($result, [1,2,3,4], "executes nested middleware components in order");

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

    public function __invoke(RequestInterface $request, $delegate)
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

        ok($dispatcher->dispatch(mock_request()) instanceof ResponseInterface);
    }
);

class PSRMiddleware implements MiddlewareInterface
{
    private $result;

    public function __construct($result = null)
    {
        $this->result = $result;
    }

    public function process(RequestInterface $request, DelegateInterface $delegate)
    {
        return $this->result ?: $delegate->process($request);
    }
}

test(
    'can dispatch PSR-15 middlewares',
    function () {
        $dispatcher = new Dispatcher([
            new PSRMiddleware(),
            new PSRMiddleware(mock_response())
        ]);

        ok($dispatcher->dispatch(mock_request()) instanceof ResponseInterface);
    }
);

class PSRServerMiddleware implements ServerMiddlewareInterface
{
    private $result;

    public function __construct($result = null)
    {
        $this->result = $result;
    }

    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        return $this->result ?: $delegate->process($request);
    }
}

test(
    'can dispatch PSR-15 server-middlewares',
    function () {
        $dispatcher = new Dispatcher([
            new PSRServerMiddleware(),
            new PSRServerMiddleware(mock_response())
        ]);

        ok($dispatcher->dispatch(mock_server_request()) instanceof ResponseInterface);
    }
);

exit(run());
