mindplay/middleman
==================

Dead simple PSR-15 / PSR-7 [middleware](#middleware) dispatcher.

Provides (optional) integration with a [variety](https://github.com/container-interop/container-interop#compatible-projects)
of dependency injection containers compliant with [container-interop](https://github.com/container-interop/container-interop).

To upgrade from 1.x to 2.x, please see [UPGRADING.md](UPGRADING.md).

[![PHP Version](https://img.shields.io/badge/php-5.4%2B-blue.svg)](https://packagist.org/packages/mindplay/middleman)
[![Build Status](https://travis-ci.org/mindplay-dk/middleman.svg?branch=master)](https://travis-ci.org/mindplay-dk/middleman)
[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/middleman/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/middleman/?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mindplay-dk/middleman/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/middleman/?branch=master)

Let's stop trying to make this complicated:

```php
use Psr\Http\Message\RequestInterface as Request;
use Zend\Diactoros\Response;

$dispatcher = new Dispatcher([
    function (Request $request, callable $next) {
        return $next($request); // delegate control to next middleware
    },
    function (Request $request) {
        return (new Response())->withBody(...); // abort middleware stack and return the response
    },
    // ...
]);

$response = $dispatcher->dispatch($request);
```

For simplicity, the middleware stack itself is immutable - if you need a stack you can manipulate, `array`, `ArrayObject`, `SplStack` etc. are all fine choices.

If you prefer implementing middleware as a reusable class, just implement `__invoke()` with the correct callback signature - or, optionally, implement [MiddlewareInterface](src/MiddlewareInterface.php), like this:

```php
use Psr\Http\Message\RequestInterface as Request;

class MyMiddleware implements MiddlewareInteface
{
    public function __invoke(Request $request, callable $next) {
        // ...
    }
}
```

Note that this works with or without `implements MiddlewareInterface`, as long as you get the callback signature right.

If you want to wire it to a [DI container](https://github.com/container-interop/container-interop#compatible-projects)
you can add a "resolver", which gets applied to every element in your middleware stack - for example:

```php
$dispatcher = new Dispatcher(
    [
        RouterMiddleware::class,
        ErrorMiddleware::class,
    ],
    new ContainerResolver($container)
);
```

Note that the "resolver" is any callable with a signature like `function (string $name) : MiddlewareInterface` - if
you want the `Dispatcher` to integrate deeply with your framework of choice, you can use a custom resolver closure.

If you want to understand precisely how this component works, the whole thing is [just one class
with a few lines of code](src/Dispatcher.php) - if you're going to base your next
project on middleware, you can (and should) understand the whole mechanism.

-----

<a name="middleware"></a>
### Middleware?

Middleware is a powerful, yet simple control facility.

If you're new to the concept of middleware, the following section will provide a basic overview.

In a nutshell, a middleware component is a function (or [MiddlewareInterface](src/MiddlewareInterface.php) instance)
that takes an incoming (PSR-7) `RequestInterface` object, and returns a `ResponseInterface` object.

It does this in one of three ways: by *assuming*, *delegating*, or *sharing* responsibility
for the creation of a response object.

##### 1. Assuming Responsibility

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

##### 2. Delegating Responsibility

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

##### 3. Sharing Responsibility

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
