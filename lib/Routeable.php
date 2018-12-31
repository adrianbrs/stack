<?php
namespace Stack\Lib;

abstract class Routeable {
    private $mode = 'router';
    public $baseURL = '/';
    public $regex = '@^/*$@';
    public $params = [];

    private $stack_global;
    private $stack_methods = [];

    private static function normalize_url(string ...$url) {
        array_unshift($url, '/');

        $url = join('/', $url);
        $url = \preg_replace('@/+@', '/', $url);
        $url = \preg_replace('@(?<=.)/$@', '', $url);

        return $url;
    }

    private static function parse_url(string $url, bool $end = true) {
        $params = [];
        $regex = ['@^'];
        
        $url = \preg_replace_callback('@:([\w-_]+)([^\/]*)@', function ($match) use (&$params) {
            $name = $match[1];
            $params[] = $name;
            return empty($match[2]) ? '([^/]+)' : $match[2];
        }, $url);
        
        if(!$end && $url !== '/') $url .= '\b';
        $regex[] = $url;


        if ($end) $regex[] = '$';
        
        $regex[] = '@';
        $regex = join('', $regex);

        return [
            'params' => $params,
            'regex'  => $regex
        ];
    }

    private function test(HttpRequest &$request, bool $removeBaseURL = true) {
        $name = static::class;

        preg_match($this->regex, $request->url, $matches, PREG_UNMATCHED_AS_NULL);
    
        $pass = count($matches) > 0 ? "PASS" : "NO PASS";

        if (count($matches) <= 0) return false;

        $matches = array_slice($matches, 1);
        
        if ($removeBaseURL) {
            $request->url = self::normalize_url(\preg_replace($this->regex, '', $request->url));
        }
        
        if (empty($request->params)) $request->params = [];
        
        $request->params = array_merge($request->params, array_combine($this->params, $matches));

        $name = static::class;

        return true;
    }

    public function __construct (
        string $url = '/',
        string $mode = 'router'
    ) {
        $this->mode = $mode;
        $this->stack_global = new MiddlewareStack;
        $this->baseURL = self::normalize_url($url);

        $url = self::parse_url($this->baseURL, $this->mode === 'route');

        $this->regex = $url['regex'];
        $this->params = $url['params'];
    }

    public function use(callable ...$middlewares) {
        $this->stack_global->use(...$middlewares);
        return $this;
    }

    private function register_method(string $method, array $middlewares) : Routeable {
        if (!isset($this->stack_methods[$method])) {
            $this->stack_methods[$method] = new MiddlewareStack;
        }

        $this->stack_methods[$method]->use(...$middlewares);

        return $this;
    }

    private function call_method_stack(HttpRequest &$request, HttpResponse &$response) {
        if (isset($this->stack_methods[$request->method])) {
            return $this->stack_methods[$request->method]->flush($request, $response);
        }


        return $this->mode === 'route'
            ? new HttpError(HttpError::METHOD_NOT_ALLOWED)
            : null
            ;
    }

    public function get(callable ...$middlewares) :Routeable {
        return $this->register_method('GET', $middlewares);
    }
    
    public function post(callable ...$middlewares) :Routeable {
        return $this->register_method('POST', $middlewares);
    }
    
    public function put(callable ...$middlewares) :Routeable {
        return $this->register_method('PUT', $middlewares);
    }
    
    public function patch(callable ...$middlewares) :Routeable {
        return $this->register_method('PATCH', $middlewares);
    }
    
    public function delete(callable ...$middlewares) :Routeable {
        return $this->register_method('DELETE', $middlewares);
    }
    
    public function head(callable ...$middlewares) :Routeable {
        return $this->register_method('HEAD', $middlewares);
    }

    public function init(
        HttpRequest &$request, 
        HttpResponse &$response
    ) {
        $test = $this->test($request, $this->mode === 'router');
        if (!$test) return false;
        
        $global = $this->stack_global->flush($request, $response);

        if (!MiddlewareStack::__check_value($global)) return $global;
        
        $method = $this->call_method_stack($request, $response, $this->mode === 'route');

        if (!MiddlewareStack::__check_value($method)) return $method;
        
        return true;
    }
}