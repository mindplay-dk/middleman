<?php

namespace mindplay\middleman;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Optionally, pass this as `$resolver` to {@see Dispatcher::__construct()} to provide
 * integration with a dependency injection container - for example:
 *
 *     $dispatcher = new Dispatcher(
 *         [
 *             RouterMiddleware::class,
 *             ErrorMiddleware::class,
 *         ],
 *         new InteropResolver($container)
 *     );
 *
 * Note that this resolver will ignore any middleware component that is not a string - so you
 * can mix component names with regular middleware closures, callable objects, and so on.
 *
 * You can use class-names or other component names, depending on what your container supports.
 *
 * @link http://www.php-fig.org/psr/psr-11/
 */
class ContainerResolver
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function __invoke($name)
    {
        if (! is_string($name)) {
            return $name; // nothing to resolve (may be a closure or other callable middleware object)
        }

        if ($this->container->has($name)) {
            return $this->container->get($name);
        }

        throw new RuntimeException("unable to resolve middleware component name: {$name}");
    }
}
