mindplay/middleman
==================

Dead simple PSR-15 / PSR-7 [middleware](#middleware) dispatcher.

Provides (optional) integration with a [variety](https://github.com/container-interop/container-interop#compatible-projects)
of dependency injection containers compatible with [PSR-11](https://www.php-fig.org/psr/psr-11/).

To upgrade between major releases, please see [UPGRADING.md](UPGRADING.md).

[![PHP Version](https://img.shields.io/badge/php-7.3%2B-blue.svg)](https://packagist.org/packages/mindplay/middleman)
[![Build Status](https://travis-ci.com/mindplay-dk/middleman.svg?branch=master)](https://travis-ci.org/mindplay-dk/middleman)
[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/middleman/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/middleman/?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mindplay-dk/middleman/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/middleman/?branch=master)

A growing catalog of PSR-15 middleware-components is available from [github.com/middlewares](https://github.com/middlewares).

## Usage

The constructor expects an array of PSR-15 `MiddlewareInterface` instances:

```php
use mindplay\middleman\Dispatcher;

$dispatcher = new Dispatcher([
    new ErrorHandlerMiddleware(...)
    new RouterMiddleware(...),
    new NotFoundMiddleware(...),
]);
```

The `Dispatcher` implements the PSR-15 `RequestHandlerInterface`. This package *only* provides the
middleware stack - to run a PSR-15 handler, for example in your `index.php` file, you need
a [PSR-15 host](https://packagist.org/packages/mindplay/sapi-host) or a similar facility.

Note that the middleware-stack in the `Dispatcher` is immutable - if you need a stack you can manipulate, `array`, `ArrayObject`, `SplStack` etc. are all fine choices.

### Anonymous Functions as Middleware

You can implement simple middleware "in place" by using anonymous functions in a middleware-stack, using a PSR-7/17 implementation such as [`nyholm/psr7`](https://packagist.org/packages/nyholm/psr7):

```php
use Psr\Http\Message\ServerRequestInterface;
use mindplay\middleman\Dispatcher;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();

$dispatcher = new Dispatcher([
    function (ServerRequestInterface $request, callable $next) {
        return $next($request); // delegate control to next middleware
    },
    function (ServerRequestInterface $request) use ($factory) {
        return $factory->createResponse(200)->withBody(...); // abort middleware stack and return the response
    },
    // ...
]);

$response = $dispatcher->handle($request);
```

### Dependency Injection via the Resolver Function

If you want to integrate with an [IOC container](https://github.com/container-interop/container-interop#compatible-projects)
you can use the `ContainerResolver` - a "resolver" is a callable which gets applied to every element in your middleware stack,
with a signature like:

    function (string $name) : MiddlewareInterface

The following example obtains middleware components on-the-fly from a DI container:

```php
$dispatcher = new Dispatcher(
    [
        RouterMiddleware::class,
        ErrorMiddleware::class,
    ],
    new ContainerResolver($container)
);
```

If you want the `Dispatcher` to integrate deeply with your framework of choice, you can implement this as a class
implementing the magic `__invoke()` function (as `ContainerResolver` does) - or "in place", as an anonymous function
with a matching signature.

If you want to understand precisely how this component works, the whole thing is [just one class
with a few lines of code](src/Dispatcher.php) - if you're going to base your next
project on middleware, you can (and should) understand the whole mechanism.

<a name="middleware"></a>
## Middleware?

Middleware is a powerful, yet simple control facility.

If you're new to the concept of middleware, the following section will provide a basic overview.

In a nutshell, a middleware component is a function (or [MiddlewareInterface](src/MiddlewareInterface.php) instance)
that takes an incoming (PSR-7) `RequestInterface` object, and returns a `ResponseInterface` object.

It does this in one of three ways: by *assuming*, *delegating*, or *sharing* responsibility
for the creation of a response object.

#### 1. Assuming Responsibility

A middleware component *assumes* responsibility by creating and returning a response object,
rather than delegating to the next middleware on the stack:

```php
use Zend\Diactoros\Response;

function ($request, $next) {
    return (new Response())->withBody(...); // next middleware won't be run
}
```

Middleware near the top of the stack has the power to completely bypass middleware
further down the stack.

#### 2. Delegating Responsibility

By calling `$next`, middleware near the top of the stack may choose to fully delegate the
responsibility for the creation of a response to other middleware components
further down the stack:

```php
function ($request, $next) {
    if ($request->getMethod() !== 'POST') {
        return $next($request); // run the next middleware
    } else {
        // ...
    }
}
```

Note that exhausting the middleware stack will result in an exception - it's assumed that
the last middleware component on the stack always produces a response of some sort, typically
a "404 not found" error page.

#### 3. Sharing Responsibility

Middleware near the top of the stack may choose to delegate responsibility for the creation of
the response to middleware further down the stack, and then make additional changes to
the returned response before returning it:

```php
function ($request, $next) {
    $result = $next($request); // run the next middleware

    return $result->withHeader(...); // then modify it's response
}
```

The middleware component at the top of the stack ultimately has the most control, as it may
override any properties of the response object before returning.
