<?php
namespace Stack\Lib;

/**
 * Class Router
 * @package Stack\Lib
 */
class Router extends Routeable {

    protected $routes = [];
    protected $sub_routers = [];

    /**
     * @param string $url
     * @param string $controllers Controllers sub namespace
     */
    public function __construct(string $url = '/', $controllers = '') {
        parent::__construct($url, 'router', $controllers);
    }

    /**
     * Register new route url
     *
     * @param $url
     * @return Route
     */
    public function route($url): Route {
        $route = new Route($url);
        $this->routes[] = $route;
        return $route;
    }

    /**
     * Middleware registration
     *
     * @param mixed ...$middlewares
     * @return Routeable|void
     */
    public function use (...$middlewares) {
        foreach ($middlewares as $middleware) {
            if ($middleware instanceof Router) {
                $middleware->controllers = is_null($middleware->controllers) ? $this->controllers : $middleware->controllers;
                $this->sub_routers[] = $middleware;
            }
            parent::use ($middleware);
        }
    }

    /**
     * Init all routes and sub-routes
     *
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param null $err
     * @return HttpError|HttpResponse|null
     * @throws \ReflectionException
     */
    public function init(HttpRequest &$request, HttpResponse &$response, $err = null) {
        if (!$this->test($request, true)) {
            return null;
        }

        $res = parent::init($request, $response);

        if (!MiddlewareStack::__check_value($res)) {
            return $res;
        }

        foreach ($this->sub_routers as $router) {
            $res = $router->init($request, $response);
            if (!is_null($res) && $res) {
                return $res;
            }

        }

        foreach ($this->routes as $route) {
            $res = $route->init($request, $response);
            if (!is_null($res) && $res) {
                return $res;
            }

        }

        return null;
    }
}