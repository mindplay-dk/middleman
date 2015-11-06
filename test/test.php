<?php

use mindplay\middleman\Dispatcher;
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

exit(run());
