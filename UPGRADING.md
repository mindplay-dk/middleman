Upgrading
=========

### From 2.x to 3.x

PSR-15 (as of `0.4`) no longer defines an interface for client-middleware.

As a consequence, this release only supports server-middleware and `ServerRequestInterface`.

We hope to see support for client-middleware emerge in the form of a new PSR in the future, but at this
point, supporting PSR-15 while directly supporting client-middleware is impossible.

### From 1.x to 2.x

#### Name Change

`InteropResolver` was renamed to `ContainerResolver` - the method-signature has not changed, so you only need
to update your imports to reference the new name.

#### MiddlewareInterface Removed

The built-in `MiddlewareInterface` has been removed, and you need to select one of the two PSR-15 interfaces,
as described below.

If you used `callable` middleware, you should expect errors/exceptions, as middleman retains support for
`callable`, but now requires a PSR-15 compatible method-signature, as described below.

#### PSR-15 Middleware

`mindplay/middleman^2` adopts the [PSR-15](https://github.com/http-interop/http-middleware) `0.2` interfaces.

This changes the middleware signature substantially, from the legacy signature:

    function (RequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface

To the PSR-15 signature:

    function (RequestInterface|ServerRequestInterface $request, DelegateInterface $delegate): ResponseInterface

PSR-15 introduces two distinct interfaces: `MiddlewareInterface` for processing `RequestInterface`, and
`ServerMiddlewareInterface` for processing `ServerRequestInterface`.

Because the Response object no longer passes down through the middleware stack, you will need to port your
middleware components from the old signature to one of the new signatures.

For example, if you had something like the following:

```php
use mindplay\middleman\MiddlewareInterface;

class MyMiddleware implements MiddlewareInterface
{
    public function __invoke(RequestInterface $request, ResponseInterface $response, $next)
    {
        if ($request instanceof ServerRequestInterface) {
            if ($request->getUri()->getPath() === "/test") {
                return $response->withBody(...);
            }
        }

        return $next($request, $response->withHeader("X-Foo", "bar"));
    }
}
```

You would need to change several things:

```php
use Interop\Http\Middleware\ServerMiddlewareInterface;

class MyMiddleware implements ServerMiddlewareInterface
{
    public function process(ServerRequestInterface $request, DelegateInterface $next)
    {
        if ($request->getUri()->getPath() === "/test") {
            return new Response()->withBody(...);
        }

        $response = $next->process($request);

        if (! $response->hasHeader("X-Foo")) {
            $response = $response->withHeader("X-Foo", "bar");
        }

        return $response;
    }
}
```

That is:

  1. The implemented interface (if any) changed from one provided by `middleman` to one provided by PSR-15 -
     which means the method-name changed from `__invoke()` to `process()`.

  2. In the case of server-middleware, the interface specifically type-hints the request as `ServerRequestInterface`,
     removing the need to type-check within the implementation.

  3. The delegate to the next middleware on the stack is type-hinted as `DelegateInterface`, and must now be
     invoked by calling it's `process()` method.

  4. The response argument no longer exists - this has two significant consequences:

     1. If we're not going to delegate to the next middleware, the middleware component needs to construct the
        response object by itself. (at the moment, this means that middleware that constructs a response needs to
        depend on a PSR-7 implementation - this is the situation until
        [PSR-17](https://github.com/php-fig/fig-standards/tree/master/proposed/http-factory) becomes available.)

     2. In this example, we were decorating the response with a default header `X-Foo: bar`, which might have been
        overwritten by the next middleware on the stack - instead, we now need to delegate to the next middleware
        *first*, and then *conditionally* decorate with a default header-value, if one is not already present.
