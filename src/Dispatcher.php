<?php

namespace mindplay\middleman;

use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-7 middleware dispatcher
 */
class Dispatcher implements MiddlewareInterface
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
    private $resolved = [];

    /**
     * @param (callable|MiddlewareInterface|mixed)[] $stack middleware stack
     * @param callable|null $resolver optional middleware resolver:
     *                                function (string $name): MiddlewareInterface
     */
    public function __construct(array $stack, callable $resolver = null)
    {
        $this->stack = $stack;
        $this->resolver = $resolver;
    }

    /**
     * Dispatches the middleware stack and returns the resulting `ResponseInterface`.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     * 
     * @throws LogicException on unexpected result from any middleware on the stack
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response)
    {
        $resolved = $this->resolve(0);

        return $resolved($request, $response);
    }

    /**
     * @inheritdoc
     */
    public function __invoke(RequestInterface $request, ResponseInterface $response, callable $next)
    {
        return $next($request, $this->dispatch($request, $response));
    }

    /**
     * @param int $index middleware stack index
     *
     * @return callable middleware delegate:
     *         function (RequestInterface $request, ResponseInterface $response): ResponseInterface
     *                  
     * @throws LogicException on unexpected middleware result
     */
    private function resolve($index)
    {
        if (isset($this->stack[$index])) {
            return function (RequestInterface $request, ResponseInterface $response) use ($index) {
                if (!isset($this->resolved[$index])) {
                    $this->resolved[$index] = $this->resolver
                        ? call_user_func($this->resolver, $this->stack[$index])
                        : $this->stack[$index]; // as-is
                }

                $middleware = $this->resolved[$index];

                $result = $middleware($request, $response, $this->resolve($index + 1));

                if (!$result instanceof ResponseInterface) {
                    throw new LogicException("unexpected middleware result");
                }

                return $result;
            };
        }

        return function (RequestInterface $request, ResponseInterface $response) {
            return $response;
        };
    }
}
