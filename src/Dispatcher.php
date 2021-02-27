<?php

namespace mindplay\middleman;

use Interop\Http\Server\MiddlewareInterface as LegacyMiddlewareInterface;
use InvalidArgumentException;
use LogicException;
use mindplay\readable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-7 / PSR-15 middleware dispatcher
 */
class Dispatcher implements LegacyMiddlewareInterface, RequestHandlerInterface
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
     * @param (callable|MiddlewareInterface|mixed)[] $stack middleware stack (with at least one middleware component)
     * @param callable|null $resolver optional middleware resolver:
     *                                function (string $name): MiddlewareInterface
     *
     * @throws InvalidArgumentException if an empty middleware stack was given
     */
    public function __construct($stack, callable $resolver = null)
    {
        if (count($stack) === 0) {
            throw new InvalidArgumentException("an empty middleware stack was given");
        }

        $this->stack = $stack;
        $this->resolver = $resolver;
    }

    /**
     * Dispatches the middleware stack and returns the resulting `ResponseInterface`.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws LogicException on unexpected result from any middleware on the stack
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $resolved = $this->resolve(0);

        return $resolved->handle($request);
    }

    /**
     * Dispatches the middleware stack and returns the resulting `ResponseInterface`.
     *
     * @deprecated in favor of identical PSR-15 method `RequestHandlerInterface::handle()`
     * 
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws LogicException on unexpected result from any middleware on the stack
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request);
    }

    /**
     * @inheritdoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->stack[] = function (ServerRequestInterface $request) use ($handler) {
            return $handler->handle($request);
        };

        $response = $this->dispatch($request);

        array_pop($this->stack);

        return $response;
    }

    /**
     * @param int $index middleware stack index
     *
     * @return RequestHandlerInterface
     */
    private function resolve($index): RequestHandlerInterface
    {
        if (isset($this->stack[$index])) {
            return new Delegate(function (ServerRequestInterface $request) use ($index) {
                $middleware = $this->resolver
                    ? call_user_func($this->resolver, $this->stack[$index])
                    : $this->stack[$index]; // as-is

                switch (true) {
                    case $middleware instanceof PsrMiddlewareInterface:
                    case $middleware instanceof LegacyMiddlewareInterface:
                        $result = $middleware->process($request, $this->resolve($index + 1));
                        break;

                    case is_callable($middleware):
                        $result = $middleware($request, $this->resolve($index + 1));
                        break;

                    default:
                        $given = readable::callback($middleware);

                        throw new LogicException("unsupported middleware type: {$given}");
                }

                if (! $result instanceof ResponseInterface) {
                    $given = readable::value($result);
                    $source = readable::callback($middleware);

                    throw new LogicException("unexpected middleware result: {$given} returned by: {$source}");
                }

                return $result;
            });
        }

        return new Delegate(function () {
            throw new LogicException("unresolved request: middleware stack exhausted with no result");
        });
    }
}
