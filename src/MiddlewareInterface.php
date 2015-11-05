<?php

namespace mindplay\middleman;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * This interface defines a middleware interface signature for type-hinting purposes.
 *
 * Implementing this is completely voluntary, it's mostly useful for indicating that
 * your class is middleware, and to ensure you type-hint the `__invoke()` method
 * signature correctly.
 */
interface MiddlewareInterface
{
    /**
     * @param RequestInterface             $request  the request
     * @param ResponseInterface            $response the response
     * @param callable|MiddlewareInterface $next     the next middleware
     *
     * @return ResponseInterface
     */
    public function __invoke(RequestInterface $request, ResponseInterface $response, callable $next);
}
