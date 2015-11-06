mindplay/middleman
==================

Dead simple PSR-7 middleware dispatcher.

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

Done.

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

Yes, really.
