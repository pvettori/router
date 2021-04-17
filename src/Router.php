<?php

namespace PVproject\Routing;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

class Router
{
    /** @var ServerRequest */
    private static $serverRequest;
    protected $arguments = [];
    protected $fallback;
    protected $prefix = '';
    protected $routes = [];

    /**
     * Create a new instance of Router.
     *
     * @param array $config [optional]
     */
    public function __construct(array $config = [])
    {
        static::$serverRequest = static::$serverRequest ?? ServerRequest::fromGlobals();

        if (is_array($arguments = $config['arguments'] ?? null)) {
            $this->arguments = $arguments;
        }

        if (is_callable($fallback = $config['fallback'] ?? null)) {
            $this->fallback = $fallback;
        }

        if (is_string($prefix = $config['prefix'] ?? null)) {
            $this->prefix = $prefix;
        }
    }

    /**
     * @return Router
     */
    public static function create(array $config = []): Router
    {
        return new static($config);
    }

    /**
     * Retrieve a named route.
     *
     * @param string $name The route name.
     *
     * @return Route|null
     */
    public function getRoute(string $name): ?Route
    {
        return $this->routes[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getRoutes(): array
    {
        return array_values($this->routes);
    }

    /**
     * Run the route matching.
     *
     * @param array $arguments     [optional] Associative array of arguments injected into the action function.
     *                             Route attributes and path parameters are also injected as arguments.
     *                             Route attributes have precendence over run arguments.
     *                             Path parameters have precendence over Route attributes and run arguments.
     * @param bool $returnTransfer [optional] Whether the response should be returned or emitted.
     *
     * @return ResponseInterface|null
     */
    public function run(array $arguments = [], bool $returnTransfer = false)
    {
        $action = $this->fallback;
        $arguments = array_merge($this->arguments, $arguments);
        $pathParams = [];
        $request = static::$serverRequest;
        $response = null;

        /** @var Route $route */
        foreach ($this->routes as $route) {
            if ($route->matches($request, $pathParams)) {
                $action = $route->getAction();
                $arguments = array_merge($arguments, $route->getAttributes());
                break;
            }
            $route = null;
        }
        $arguments = array_merge($arguments, ['parameters' => $pathParams], $pathParams);

        if ($action) {
            $middleware = [];
            $arguments = array_merge($arguments, compact('request'));
            if (isset($route)) {
                $arguments['route'] = $route;
                $middleware = $route->getMiddleware();
            }

            if ($middleware) {
                $middleware = array_reverse(array_values($middleware));
                foreach ($middleware as $index => &$handler) {
                    $next = $middleware[$index - 1]['function'] ?? function ($request) use ($action, $arguments) {
                        return $action(...static::prepareNamedArguments($action, array_merge($arguments, compact('request'))));
                    };
                    $handler['function'] = function ($request) use ($handler, $next) {
                        return $handler['function']($request, $next, ...$handler['extra_arguments']);
                    };
                }
                $action = end($middleware)['function'];
                $arguments = compact('request');
            }

            $response = static::callWithNamedArguments($action, $arguments);
        }

        if ($returnTransfer) {
            return $response;
        }

        if (!is_a($response, ResponseInterface::class)) {
            $response = new Response();
        }

        /** @var ResponseInterface $response */
        header(sprintf('HTTP/%s %s %s', $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase()));
        foreach ($response->getHeaders() as $name => $values) {
            header(sprintf('%s: %s', $name, implode(', ', $values)));
        }
        echo (string) $response->getBody();
    }

    /**
     * Add routes grouped by prefix.
     *
     * @param array  $routes
     * @param string $prefix
     * @param array  $middleware [optional]
     *
     * @return Router
     */
    public function addRouteGroup(string $prefix, array $routes, array $middleware = null): Router
    {
        $prefix = preg_replace('/\/$/', '', '/'.preg_replace('/(^\/|\/$)/', '', $prefix));

        foreach ($routes as $name => $route) {
            if (is_string($name)) {
                $route = $route->withName($name);
            }

            if ($prefix !== '') {
                $route = $route->withPath($prefix.$route->getPath());
            }

            if ($middleware) {
                $route = $route->withMiddleware(...$middleware);
            }

            $this->addRoute($route);
        }

        return $this;
    }

    /**
     * Add a route.
     * If a domain has been defined then it is prepended to the path.
     *
     * @param Route $route
     *
     * @return Router
     */
    public function addRoute(Route $route): Router
    {
        $route = $route->withPath($this->prefix.$route->getPath());

        if ($name = $route->getName()) {
            $this->routes[$name] = $route;
        } else {
            $this->routes[] = $route;
        }

        return $this;
    }

    /**
     * Set a fallback route.
     *
     * @param callable|string $action A function (or invokable class name) to call if the route matches the request.
     *
     * @return Router
     */
    public function setFallback($action): Router
    {
        if (is_callable($action)) {
            /* PASS */
        } elseif (is_string($action) && class_exists($action) && is_callable($instance = new $action)) {
            $action = $instance;
        } else {
            throw new \InvalidArgumentException(sprintf(
                'Invalid argument 1 for: %s(); expected calable or string, %s given',
                __METHOD__, is_object($action) ? get_class($action) : gettype($action)
            ));
        }

        $this->fallback = $action;

        return $this;
    }

    /**
     * Set a prefix for the new routes.
     *
     * @param string $prefix
     *
     * @return Router
     */
    public function setPrefix(string $prefix = null): Router
    {
        $this->prefix = preg_replace('/\/$/', '', '/'.preg_replace('/(^\/|\/$)/', '', (string) $prefix));

        return $this;
    }

    /**
     * Set a route.
     * If a domain has been defined then it is prepended to the path.
     *
     * @param string          $path    The route path.
     *                                 Path parameters must be enclosed in curly braces.
     * @param callable|string $action  A function (or invokable class name) to call if the route matches the request.
     * @param array           $methods [optional] The methods that the route should match.
     *                                 If empty then the route matches any method.
     * @param string          $name    [optional] A name for the route.
     *
     * @return Route
     */
    public function setRoute(string $path, $action, array $methods = [], string $name = null): Route
    {
        $route = Route::create($this->prefix.$path, $action, $methods, $name);

        if ($name = $route->getName()) {
            $this->routes[$name] = $route;
        } else {
            $this->routes[] = $route;
        }

        return $route;
    }

    /**
     * Call a function with arguments in the correct order.
     *
     * @param string|callable $function   The function that recieves the arguments.
     * @param array           $parameters [optional] An associative array of possible function arguments.
     *
     * @return mixed
     */
    private static function callWithNamedArguments($function, array $parameters = [])
    {
        return call_user_func_array($function, static::prepareNamedArguments($function, $parameters));
    }

    /**
     * Prepare a function's arguments in the correct order.
     *
     * @param string|callable $function   The function that recieves the arguments.
     * @param array           $parameters [optional] An associative array of possible function arguments.
     *
     * @return array
     */
    private static function prepareNamedArguments($function, array $parameters = []): array
    {
        $reflectionFunction = is_object($function)
            ? new \ReflectionMethod($function, '__invoke')
            : new \ReflectionFunction($function);
        $arguments = [];
        foreach ($reflectionFunction->getParameters() as $argument) {
            $argumentName = $argument->getName();
            $argumentDefault = $argument->isOptional() ? $argument->getDefaultValue() : null;
            $arguments[$argumentName] = $parameters[$argumentName] ?? $argumentDefault;
        }

        return array_values($arguments);
    }
}
