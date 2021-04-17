<?php

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use PVproject\Routing\Route;
use PVproject\Routing\Router;

require_once __DIR__.'/assets/InvokableClass.php';
require_once __DIR__.'/assets/MiddlewareClass.php';

function change_method($request, $handler, $method) {
    $request = $request->withMethod($method);
    return $handler($request);
}

class RouterTest extends TestCase
{
    public function testCanBeCreated()
    {
        $router = new Router();
        $this->assertInstanceOf(Router::class, $router);
    }

    public function testCanBeCreatedFromFactoryMethod()
    {
        $router = Router::create();
        $this->assertInstanceOf(Router::class, $router);
    }

    public function testCanAddRoute()
    {
        $router = Router::create()->addRoute(Route::get('/', function () {}));
        $this->assertCount(1, $router->getRoutes());
    }

    public function testCanSetRoute()
    {
        $router = new Router();
        $route = $router->setRoute('/', function () {});
        $this->assertInstanceOf(Route::class, $route);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanMatchRoute()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/some/other/path';

        $router = new Router();
        $router->setRoute('/some/path', function () { return 'not found'; });
        $router->setRoute('/some/other/path', function () { return 'not found'; }, ['POST']);
        $router->setRoute('/some/other/path', function () { return 'found'; });
        $this->assertEquals('found', $router->run([], true));
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanSetPrefix()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/some/path';

        $router = new Router();
        $router->setPrefix('/some');
        $router->setRoute('/some/path', function () { return 'not found'; });
        $router->setRoute('/path', function () { return 'found'; });
        $this->assertEquals('found', $router->run([], true));
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanSetFallback()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/fallback';

        $router = new Router();
        $router->setFallback(function () { return 'found'; });
        $router->setRoute('/some/path', function () { return 'not found'; });
        $router->setRoute('/some/other/path', function () { return 'not found'; });
        $this->assertEquals('found', $router->run([], true));
    }

    public function testCanSetInvokableClassNameAsFallback()
    {
        try {
            Router::create()->setFallback(\InvokableClass::class);
            $this->assertTrue(true);
        } catch (\Exception $ex) {
            $this->fail($ex->getMessage());
        }
    }

    public function testThrowsExceptionOnInvalidFallback()
    {
        $this->expectException(\InvalidArgumentException::class);
        Router::create()->setFallback(\stdClass::class);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanGroupRoutes()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/group/path';

        $router = new Router();
        $router->addRouteGroup('/group', [
            Route::get('/path', function ($request) { return $request->getUri()->getPath(); })
        ]);
        $this->assertEquals('/group/path', $router->run([], true));
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanInjectArguments()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/some/path';

        $result = Router::create()
            ->addRoute(Route::get('/some/{param}', function ($param, $parameters, $request, $route) { return func_get_args(); }))
            ->run([], true);
        $this->assertIsArray($result);
        $this->assertEquals('path', $result[0]);
        $this->assertEquals(['param' => 'path'], $result[1]);
        $this->assertInstanceOf(RequestInterface::class, $result[2]);
        $this->assertInstanceOf(Route::class, $result[3]);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanRunMiddlewareClasses()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/some/path';

        $response = Router::create()
            ->addRoute(
                Route::get('/some/{param}', function () {
                    return new Response(201);
                })->withMiddleware(
                    ['\MiddlewareClass']
                )
            )
            ->run([], true);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(201, $response->getStatusCode());
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanRunMiddlewareFunctions()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/some/path';

        $response = Router::create()
            ->addRoute(
                Route::get('/some/{param}', function ($param, $request) {
                    return [$param, $request->getMethod()];
                })->withMiddleware(
                    ['change_method', 'PATCH'],
                    ['change_method', 'POST']
                )
            )
            ->run([], true);
        $this->assertEquals(['path', 'POST'], $response);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCanRunWithExtraArguments()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/some/path';

        $response = Router::create([
            'arguments' => [
                'extra1' => 'value1',
                'extra2' => 'value2',
            ],
        ])
            ->addRoute(Route::get('/some/path', function ($extra1, $extra2) { return [$extra1, $extra2]; }))
            ->run(['extra2' => 'value3'], true);
        $this->assertEquals(['value1', 'value3'], $response);
    }

    /**
     * @runInSeparateProcess
     */
    public function testRegressionAgainstGhostRoute()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/some/path';

        $response = Router::create()
            ->addRouteGroup('/', [
                Route::get('/some/other/path', function ($request) { return $request->getMethod(); })
            ], [
                ['change_method', 'POST']
            ])
            ->setFallback(function ($request) { return $request->getMethod(); })
            ->run([], true);
        $this->assertEquals('GET', $response);
    }
}
