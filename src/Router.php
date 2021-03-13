<?php

namespace PVproject\Routing;

use GuzzleHttp\Psr7\ServerRequest;

class Router
{
    /** @var ServerRequest */
    private static $serverRequest;
    protected $fallback;
    protected $prefix = '';
    protected $routes = [];

    public function __construct()
    {
        static::$serverRequest = static::$serverRequest ?? ServerRequest::fromGlobals();
    }

    /**
     * @return Router
     */
    public static function create(): Router
    {
        return new static();
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
     */
    public function run()
    {
        $request = static::$serverRequest;
        $pathParams = [];
        $action = $this->fallback;

        /** @var Route $route */
        foreach ($this->routes as $route) {
            if ($route->matches($request, $pathParams)) {
                $action = $route->getAction();
                break;
            }
        }

        if ($action) {
            $handlers = [];
            $defaultArguments = compact('request');
            if (isset($route)) {
                $defaultArguments['route'] = $route;
                $handlers = $route->getMiddleware();
            }

            $prepareArguments = function (array $defaultArguments = []) use ($action, $pathParams) {
                $reflectionFunction = new \ReflectionFunction($action);
                $reflectionArguments = $reflectionFunction->getParameters();
                $arguments = [];
                foreach ($reflectionArguments as $argument) {
                    $argumentName = $argument->getName();
                    if ($value = $pathParams[$argumentName] ?? null) {
                        $arguments[$argumentName] = $value;
                    } elseif ($value = $defaultArguments[$argumentName]) {
                        $arguments[$argumentName] = $value;
                    } else {
                        $arguments[$argumentName] = $argument->getDefaultValue();
                    }
                }

                return $arguments;
            };

            if ($handlers) {
                $handlers = array_reverse(array_values($handlers));
                foreach ($handlers as $index => &$handler) {
                    $next = $handlers[$index - 1] ?? function ($request) use ($action, $defaultArguments, $prepareArguments) {
                        $defaultArguments['request'] = $request;
                        $arguments = array_values($prepareArguments($defaultArguments));
                        return $action(...$arguments);
                    };
                    $handler = function ($request) use ($handler, $next) {
                        $handler = (array) $handler;
                        $fn = array_shift($handler);
                        return $fn($request, $next, ...$handler);
                    };
                }
                $response = call_user_func(end($handlers), $request);
            } else {
                $response = call_user_func_array($action, $prepareArguments($defaultArguments));
            }
        }

        return $response ?? null;
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
     * Set a fallback route.
     *
     * @param callable $action
     *
     * @return Router
     */
    public function setFallback(callable $action): Router
    {
        $this->fallback = $action;

        return $this;
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
                $route = $route->withMiddleware($middleware);
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
     * Set a route.
     * If a domain has been defined then it is prepended to the path.
     *
     * @param string   $path    The route path.
     *                          Path parameters must be enclosed in curly braces.
     * @param callable $action  A function to call if the route matches the request.
     * @param array    $methods [optional] The methods that the route should match.
     *                          If empty then the route matches any method.
     * @param string   $name    [optional] A name for the route.
     *
     * @return Route
     */
    public function setRoute(string $path, callable $action, array $methods = [], string $name = null): Route
    {
        $route = Route::create($this->prefix.$path, $action, $methods, $name);

        if ($name = $route->getName()) {
            $this->routes[$name] = $route;
        } else {
            $this->routes[] = $route;
        }

        return $route;
    }
}
