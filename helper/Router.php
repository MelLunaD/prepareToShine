<?php

class Router
{
    private $config;
    private $defaultController;
    private $defaultMethod;
    private $controllersPublicos;
    private $rolesPorController;

    // $controllersPublicos: los únicos que se pueden usar sin haber iniciado sesión
    // (login y registro). Cualquier otro pedido sin sesión va a /login.
    // $rolesPorController: los controllers que solo puede usar un rol, por ejemplo
    // ["editor" => ["editor"]]. Los que no están en la lista los usa cualquier logueado.
    public function __construct($config, $defaultController, $defaultMethod, array $controllersPublicos = [], array $rolesPorController = [])
    {
        $this->config              = $config;
        $this->defaultController   = $defaultController;
        $this->defaultMethod       = $defaultMethod;
        $this->controllersPublicos = $controllersPublicos;
        $this->rolesPorController  = $rolesPorController;
    }

    public function dispatch($controllerName, $method)
    {
        $controllerName = is_string($controllerName) ? strtolower($controllerName) : $this->defaultController;
        $method         = is_string($method) ? $method : $this->defaultMethod;

        // "index", "index.html", "index.php"...: algunos hostings piden la raíz así
        // (DirectoryIndex). Es la página principal, no un 404.
        if (preg_match('/^index(\.\w+)?$/', $controllerName)) {
            $controllerName = $this->defaultController;
        }

        // Una dirección que no lleva a ningún controller es un 404 (antes caía en el lobby sin
        // avisar). Se revisa antes que la sesión: así tampoco se guarda para después del login.
        if (!method_exists($this->config, $this->getFactoryMethodName($controllerName))) {
            $this->noEncontrada();
        }

        if (!Sesion::estaLogueada() && !in_array($controllerName, $this->controllersPublicos, true)) {
            $this->recordarPaginaPedida();
            Redirect::to('/login');
        }

        // Control de acceso por rol: un jugador que escribe /editor a mano vuelve al lobby.
        if (!$this->rolPuedeUsar($controllerName)) {
            Redirect::toIndex();
        }

        $controller = $this->config->{$this->getFactoryMethodName($controllerName)}();

        // is_callable y no method_exists: así por URL solo se llega a los métodos públicos.
        // Un método que no existe (o privado) también es un 404.
        // Los que empiezan con "__" (__construct...) son de PHP, nunca páginas.
        if (strpos($method, "__") === 0 || !is_callable([$controller, $method])) {
            $this->noEncontrada();
        }

        $controller->{$method}();
    }

    // Muestra la página 404 (con código HTTP 404) y corta el pedido.
    private function noEncontrada()
    {
        $this->config->getErrorController()->noEncontrada();
        exit();
    }

    // Si abrió una página con el navegador (por ejemplo, un perfil desde el QR), se guarda para
    // volver a ella después del login. No se guardan los envíos de formularios (POST) ni los
    // pedidos de AJAX (fetch no pide "text/html"), porque no son páginas para mostrar.
    private function recordarPaginaPedida()
    {
        $esPaginaPedida = ($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET"
            && strpos($_SERVER["HTTP_ACCEPT"] ?? "", "text/html") !== false;

        if ($esPaginaPedida && ($_SERVER["REQUEST_URI"] ?? "/") !== "/") {
            Sesion::guardarDestino($_SERVER["REQUEST_URI"]);
        }
    }

    private function rolPuedeUsar($controllerName)
    {
        if (!isset($this->rolesPorController[$controllerName])) {
            return true;
        }

        $usuario = Sesion::usuario();
        return $usuario !== null && in_array($usuario["rol"], $this->rolesPorController[$controllerName], true);
    }

    private function getFactoryMethodName($controllerName)
    {
        return "get" . ucfirst($controllerName) . "Controller";
    }
}
