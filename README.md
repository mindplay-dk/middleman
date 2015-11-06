mindplay/middleman
==================

Dead simple PSR-7 [middleware](#middleware) dispatcher.

[![Build Status](https://travis-ci.org/mindplay-dk/middleman.svg)](https://travis-ci.org/mindplay-dk/middleman)

[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/middleman/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/middleman/?branch=master)

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mindplay-dk/middleman/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/middleman/?branch=master)

Let's stop trying to make this complicated:

```php
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

$dispatcher = new Dispatcher([
    function ($request, $response, $next) {
        return $next($request, $response); // delegate control to next middleware
    },
    function ($request, $response, $next) {
        return $response->withBody(...); // abort middleware stack and return the response
    },
    // ...
]);

$result = $dispatcher->dispatch($request, $response);
```

If you prefer middleware as classes, optionally implement [MiddlewareInterface](src/MiddlewareInterface.php):

```php
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class MyMiddleware implements MiddlewareInteface
{
    public function __invoke(Request $request, Response $response, callable $next) {
        // ...
    }
}
```

If you want to  wire it to a [DI container](https://github.com/container-interop/container-interop#compatible-projects)
you can add a "resolver" function, which gets applied to every element in your middleware stack - for example:

```php
$dispatcher = new Dispatcher(
    [
        RouterMiddleware::class,
        ErrorMiddleware::class,
    ],
    function ($type) use ($container) {
        return $container->get($type);
    }
);
```

That's all.

If you want to understand precisely how it works, the whole thing is [just one class
with a few lines of code](src/Dispatcher.php) - if you're going to base your next
project on middleware, you can (and should) understand the whole mechanism.

-----

<a name="middleware"></a>
### Middleware?

Middleware is a powerful, yet simple control facility.

In a nutshell, a middleware component is a function (or [MiddlewareInterface](src/MiddlewareInterface.php) instance)
that takes an incoming (PSR-7) `RequestInterface` object, and returns a `ResponseInterface` object.

It does this in one of three ways: by *assuming*, *delegating*, or *sharing* control.

##### 1. Assuming Control

When middleware *assumes* control, it doesn't delegate to the next middleware on the stack:

```php
function ($request, $response, $next) {
    return $response->withBody(...); // next middleware won't be run
}
```

Middleware near the top of the stack has the power to take away control from middleware
further down the stack.

##### 2. Delegating Control

If middleware decides the request context isn't relevant to it, it may *delegate* control
to the next middleware on the stack:

```php
function ($request, $response, $next) {
    if ($request->getMethod() !== 'POST') {
        return $next($request, $response); // run the next middleware
    } else {
        // ...
    }
}
```

Middleware near the top of the stack may choose to relinquish control, and delegate
the responsibility of producing a response, to middleware further down the stack.

##### 3. Sharing Control

When middleware *shares* control, it first delegates, and then reassumes control:

```php
function ($request, $response, $next) {
    $result = $next($request, $response); // run the next middleware

    return $result->withHeader(...); // then modify it's response
}
```

Middleware near the top of the stack may choose to first delegate control to middleware
further down the stack, then reassume control, and possibly make additional changes to
the returned response.

[![Build Status](https://travis-ci.org/mindplay-dk/middleman.svg)](https://travis-ci.org/mindplay-dk/middleman)

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mindplay-dk/middleman/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/mindplay-dk/middleman/?branch=master)
