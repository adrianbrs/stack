<?php
namespace Stack\Lib;

class Router extends Routeable {
    private $routes = [];
    private $sub_routers = [];

    public function __construct(string $url = '/') {
        parent::__construct($url, 'router');
    }

    public function route($url) : Route {
        $route = new Route($url);
        $this->routes[] = $route;
        return $route;
    }

    public function use(...$middlewares) {
        foreach($middlewares as $middleware) {
            if ($middleware instanceof Router) {
                $this->sub_routers[] = $middleware;
            } else if (is_string($middleware)) {
                parent::use(new $middleware);
            } else parent::use($middleware);
        }
    }

    public function init(HttpRequest &$request, HttpResponse &$response) {
        $errors = parent::init($request, $response, 'router');

        if (!MiddlewareStack::__check_value($errors)) return $errors;

        foreach ($this->sub_routers as $router) {
            $errors = $router->init($request, $response);

            if ($errors === true || ($errors instanceof HttpResponse)) {
                $errors = true;
                break;
            };

            if ($errors === false) return null;
            if (is_null($errors)) continue;
            else return $errors;
        }

        foreach ($this->routes as $route) {
            $errors = $route->init($request, $response);

            if ($errors === true) return true;
            if ($errors === false) return null;
            if (is_null($errors)) continue;
            else return $errors;
        }

        return $errors;
    }
}