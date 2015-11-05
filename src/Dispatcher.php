<?php

namespace mindplay\middleman;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-7 middleware dispatcher
 */
class Dispatcher
{
    /**
     * @var callable middleware resolver
     */
    private $resolver;

    /**
     * @var mixed[] unresolved middleware stack
     */
    private $stack;

    /**
     * @var (callable|MiddlewareInterface)[] resolved middleware stack
     */
    private $resolved = array();

    /**
     * @param (callable|MiddlewareInterface|mixed)[] $stack middleware stack
     * @param callable|null $resolver optional middleware resolver
     */
    public function __construct(array $stack, callable $resolver = null)
    {
        $this->stack = $stack;
        $this->resolver = $resolver;
    }

    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response)
    {
        return call_user_func($this->resolve(0), $request, $response);
    }

    /**
     * @return callable function (RequestInterface $request, ResponseInterface $response): ResponseInterface
     */
    protected function resolve($index)
    {
        if (isset($this->stack[$index])) {
            if (!isset($this->resolved[$index])) {
                $this->resolved[$index] = $this->resolver
                    ? call_user_func($this->resolver, $this->stack[$index])
                    : $this->stack[$index]; // as-is
            }

            $middleware = $this->resolved[$index];

            return function ($request, $response) use ($middleware, $index) {
                return $middleware($request, $response, $this->resolve($index + 1));
            };
        }

        return function($request, $response) {
            return $response;
        };
    }
}
