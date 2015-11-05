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
        $dispatcher = new Dispatcher(array());

        $response = mock_response();

        $returned = $dispatcher->dispatch(mock_request(), $response);

        eq($returned, $response, 'it returns the response');
    }
);

test(
    'Can dispatch middleware',
    function () {
        $called = false;

        $dispatcher = new Dispatcher(array(
            function (RequestInterface $request, ResponseInterface $response, $next) use (&$called) {
                $called = true;

                return $next($request, $response);
            }
        ));

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

        $dispatcher = new Dispatcher(array(
            function (RequestInterface $request, ResponseInterface $response, $next) use (&$called_one, &$order) {
                $called_one = $order++;

                return $next($request, $response);
            },
            function (RequestInterface $request, ResponseInterface $response, $next) use (&$called_two, &$order) {
                $called_two = $order++;

                return $next($request, $response);
            }
        ));

        $response = mock_response();

        $returned = $dispatcher->dispatch(mock_request(), $response);

        ok($called_one === 1, 'the first middleware was dispatched');
        ok($called_two === 2, 'the second middleware was dispatched');

        eq($returned, $response, 'it returns the response');
    }
);

exit(run());
