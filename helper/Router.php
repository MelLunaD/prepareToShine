<?php

class Router
{
    private $config;
    private $defaultController;
    private $defaultMethod;
    private $controllersPublicos;

    // $controllersPublicos: los únicos que se pueden usar sin haber iniciado sesión
    // (login y registro). Cualquier otro pedido sin sesión va a /login.
    public function __construct($config, $defaultController, $defaultMethod, array $controllersPublicos = [])
    {
        $this->config              = $config;
        $this->defaultController   = $defaultController;
        $this->defaultMethod       = $defaultMethod;
        $this->controllersPublicos = $controllersPublicos;
    }

    public function dispatch($controllerName, $method)
    {
        $controllerName = is_string($controllerName) ? strtolower($controllerName) : $this->defaultController;
        $method         = is_string($method) ? $method : $this->defaultMethod;

        if (!Sesion::estaLogueada() && !in_array($controllerName, $this->controllersPublicos, true)) {
            Redirect::to('/login');
        }

        $controller = $this->getController($controllerName);
        $method     = $this->getMethod($controller, $method);
        $controller->{$method}();
    }

    private function getController($controllerName)
    {
        $factoryMethod = $this->getFactoryMethodName($controllerName);

        if (!method_exists($this->config, $factoryMethod)) {
            $factoryMethod = $this->getFactoryMethodName($this->defaultController);
        }

        return $this->config->{$factoryMethod}();
    }

    private function getFactoryMethodName($controllerName)
    {
        return "get" . ucfirst($controllerName) . "Controller";
    }

    private function getMethod($controller, $method)
    {
        // is_callable y no method_exists: así por URL solo se llega a los métodos públicos.
        return is_callable([$controller, $method]) ? $method : $this->defaultMethod;
    }
}
