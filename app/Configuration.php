<?php

namespace app;

use app\controller\HomeController;
use app\controller\LoginController;
use app\controller\RegistroController;
use app\model\FotoPerfilModel;
use app\model\PaisModel;
use app\model\UsuarioModel;
use app\model\ValidacionModel;
use Mailer;
use MustacheRender;
use MyDatabase;
use Router;

require_once("helper/Autoloader.php");

// Para agregar una pantalla nueva: crear su Controller, Model y View,
// y sumar acá un get{Nombre}Controller() público que los arme.
class Configuration
{
    // Controllers que se pueden usar sin haber iniciado sesión. Todo lo demás pide login.
    const CONTROLLERS_PUBLICOS = ["login", "registro"];

    private $config;
    private $database;

    public function __construct()
    {
        $this->config = parse_ini_file("config/config.ini");
    }

    public function getHomeController()
    {
        return new HomeController(
            $this->getRender()
        );
    }

    public function getLoginController()
    {
        return new LoginController(
            $this->getUsuarioModel(),
            $this->getValidacionModel(),
            $this->getRender()
        );
    }

    public function getRegistroController()
    {
        return new RegistroController(
            $this->getUsuarioModel(),
            new PaisModel($this->getDatabase()),
            new FotoPerfilModel(),
            $this->getValidacionModel(),
            $this->getRender()
        );
    }

    // Los privados

    private function getUsuarioModel()
    {
        return new UsuarioModel(
            $this->getDatabase()
        );
    }

    private function getValidacionModel()
    {
        return new ValidacionModel(
            $this->getDatabase(),
            new Mailer($this->config),
            $this->getRender()
        );
    }

    // Una sola conexión por pedido, aunque varios models la usen.
    private function getDatabase()
    {
        if ($this->database === null) {
            $this->database = new MyDatabase($this->config["db_host"],
                $this->config["db_user"],
                $this->config["db_pass"],
                $this->config["db_name"]
            );
        }

        return $this->database;
    }

    private function getRender()
    {
        return new MustacheRender();
    }

    public function getRouter()
    {
        return new Router($this, "home", "show", self::CONTROLLERS_PUBLICOS);
    }
}
