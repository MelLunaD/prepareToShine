<?php

namespace app;

use app\controller\HomeController;
// use app\controller\LoginController;
// use app\model\LoginModel;
use MustacheRender;
use MyDatabase;
use Router;

require_once("helper/Autoloader.php");

// Para agregar una pantalla nueva: crear su Controller, Model y View,
// y sumar acá un get{Nombre}Controller() público que los arme.
class Configuration
{

    public function __construct()
    {
    }

    public function getHomeController()
    {
        return new HomeController(
            $this->getRender()
        );
    }

    // Login base: desconectado hasta que definamos la base de datos.
    // Para habilitarlo, descomentar este método, los "use" de arriba y getLoginModel().
    //
    // public function getLoginController()
    // {
    //     return new LoginController(
    //         $this->getLoginModel(),
    //         $this->getRender()
    //     );
    // }

    // Los privados

    // private function getLoginModel()
    // {
    //     return new LoginModel(
    //         $this->getDatabase()
    //     );
    // }

    private function getDatabase()
    {
        $config = parse_ini_file("config/config.ini");

        return new MyDatabase($config["db_host"],
            $config["db_user"],
            $config["db_pass"],
            $config["db_name"]
        );
    }

    private function getRender()
    {
        return new MustacheRender();
    }

    public function getRouter()
    {
        return new Router($this, "home", "show");
    }
}
