<?php

namespace Stack\Lib;

class MiddlewareStack {
    public $stack = [];

    public static function __check_value ($v) {
        return is_null($v) || $v instanceof HttpResponse;
    }
    /**
     * Registra middlewares na stack
     * @param callable|Routeable $middlewares Lista de middlewares
     */
    public function use(...$middlewares) {
        $middlewares = array_filter($middlewares, function($middleware) {
            return is_callable($middleware) || ($middleware instanceof self) || ($middleware instanceof Router);
        });

        $this->stack = array_merge($this->stack, $middlewares);

        return $this;
    }
    
    public function next (&$req, &$res, $err = null) {
        if (count($this->stack) <= 0) {
            return $err;
        };
        
        $middleware = array_shift($this->stack);
        
        if (is_callable($middleware)) {
            $reflex = new \ReflectionFunction($middleware);
            $args_len = $reflex->getNumberOfParameters();

            if ($err instanceof HttpResponse) return $err;
            
            if ($err === null && $args_len === 2) {
                $err = $middleware($req, $res);
                return $this->next($req, $res, $err);
            }

            if ($err !== null && $args_len === 3) {
                $err = $middleware($err, $req, $res);
                return $this->next($req, $res, $err);
            }
        } else if ($middleware instanceof self) {
            $err = $middleware->next($req, $res, $err);
        }
        
        return $this->next($req, $res, $err);
    }
}