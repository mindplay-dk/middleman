<?php

use Interop\Container\ContainerInterface;
use mindplay\middleman\Dispatcher;
use mindplay\middleman\InteropResolver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

configure()->enableCodeCoverage(__DIR__ . '/build/clover.xml', dirname(__DIR__) . '/src');

/** @return RequestInterface */
function mock_request() {
    return Mockery::mock('Psr\Http\Message\RequestInterface');
}

/** @return ResponseInterface */
function mock_response() {
    return Mockery::mock('Psr\Http\Message\ResponseInterface');
}

test(
    'Response can pass thru empty stack',
    function () {
        $dispatcher = new Dispatcher([]);

        $response = mock_response();

        $returned = $dispatcher->dispatch(mock_request(), $response);

        eq($returned, $response, 'it returns the response');
    }
);

test(
    'Can dispatch middleware',
    function () {
        $called = false;

        $dispatcher = new Dispatcher([
            function (RequestInterface $request, ResponseInterface $response, $next) use (&$called) {
                $called = true;

                return $next($request, $response);
            }
        ]);

        $response = mock_response();

        $returned = $dispatcher->dispatch(mock_request(), $response);

        ok($called, 'the middleware was dispatched');
        eq($returned, $response, 'it returns the response');
    }
);

test(
    'Can dispatch middleware stack',
    function () {
        $called_one = 0;
        $called_two = 0;
        $order = 1;

        $dispatcher = new Dispatcher([
            function (RequestInterface $request, ResponseInterface $response, $next) use (&$called_one, &$order) {
                $called_one = $order++;

                return $next($request, $response);
            },
            function (RequestInterface $request, ResponseInterface $response, $next) use (&$called_two, &$order) {
                $called_two = $order++;

                return $next($request, $response);
            }
        ]);

        $response = mock_response();

        $returned = $dispatcher->dispatch(mock_request(), $response);

        ok($called_one === 1, 'the first middleware was dispatched');
        ok($called_two === 2, 'the second middleware was dispatched');

        eq($returned, $response, 'it returns the response');
    }
);

test(
    'Can resolve middleware',
    function () {
        $initialized = [
            'one' => 0,
            'two' => 0,
        ];

        $called = [
            'one' => 0,
            'two' => 0,
        ];

        $dispatcher = new Dispatcher(
            ['one', 'two'],
            function ($init) use (&$called, &$initialized) {
                $initialized[$init] += 1;

                return function ($request, $response, $next) use (&$called, $init) {
                    $called[$init] += 1;

                    return $next($request, $response);
                };
            }
        );

        $response = mock_response();

        $dispatcher->dispatch(mock_request(), $response);

        eq($called['one'], 1, 'the first middleware was dispatched (once)');
        eq($called['two'], 1, 'the second middleware was dispatched (once)');

        $returned = $dispatcher->dispatch(mock_request(), $response);

        eq($returned, $response, 'it returned the response');

        // can dispatch the same middleware stack more than once:

        eq($called['one'], 2, 'the first middleware was dispatched (twice)');
        eq($called['two'], 2, 'the second middleware was dispatched (twice)');

        eq($returned, $response, 'it returned the response');

        // initialization occurs only once:

        eq($initialized['one'], 1, 'the first middleware was initialized (once)');
        eq($initialized['two'], 1, 'the second middleware was initialized (once)');
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

        $container->contents['foo'] = function (RequestInterface $request, ResponseInterface $response, $next) use (&$called_indirect) {
            $called_indirect = true;

            $next($request, $response);
        };

        $resolver = new InteropResolver($container);

        $dispatcher = new Dispatcher(
            [
                'foo', // to be resolved by $container via InteropResolver
                function (RequestInterface $request, ResponseInterface $response) use (&$called_direct) {
                    $called_direct = true;
                }
            ],
            $resolver
        );

        $dispatcher->dispatch(mock_request(), mock_response());

        ok($called_indirect, 'middleware gets resolved via DI container and invoked');
        ok($called_direct, 'other middleware gets invoked directly');

        // test with un-resolvable component name:

        $dispatcher = new Dispatcher(['bar'], $resolver);

        expect(
            'RuntimeException',
            'should throw for middleware that cannot be resolved',
            function () use ($dispatcher) {
                $dispatcher->dispatch(mock_request(), mock_response());
            }
        );
    }
);

exit(run());
