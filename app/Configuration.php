<?php

namespace app;

use app\controller\HomeController;
use app\controller\LoginController;
use app\controller\RegistroController;
use app\controller\PerfilController;
use app\controller\PartidaController;
use app\controller\RankingController;
use app\controller\EditorController;
use app\controller\AdminController;
use app\controller\PreguntaController;
use app\model\CargaCsvModel;
use app\model\CementerioModel;
use app\model\FotoPerfilModel;
use app\model\PaisModel;
use app\model\UsuarioModel;
use app\model\ValidacionModel;
use app\model\PerfilModel;
use app\model\UbicacionModel;
use app\model\PartidaModel;
use app\model\RankingModel;
use app\model\PreguntaModel;
use app\model\ReporteModel;
use app\model\SolicitudEditorModel;
use app\model\EstadisticaModel;
use app\model\PreguntaEstadisticaModel;
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

    // Controllers que pide un rol (el Router los controla). Los demás los usa cualquier logueado.
    const ROLES_POR_CONTROLLER = [
        "editor" => ["editor"],
        "admin" => ["admin"],
        // El administrador no juega: no arma partidas ni reporta o sugiere preguntas.
        "partida" => ["jugador", "editor"],
        "pregunta" => ["jugador", "editor"],
    ];

    private $config;
    private $database;

    public function __construct()
    {
        $this->config = parse_ini_file("config/config.ini");
    }

    public function getHomeController()
    {
        return new HomeController(
            $this->getPartidaModel(),
            new RankingModel($this->getDatabase()),
            new SolicitudEditorModel($this->getDatabase()),
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

    public function getPartidaController()
    {
        return new PartidaController(
            $this->getPartidaModel(),
            $this->getReporteModel(),
            new RankingModel($this->getDatabase()),
            $this->getRender()
        );
    }

    public function getRankingController()
    {
        return new RankingController(
            new RankingModel($this->getDatabase()),
            new CementerioModel($this->getDatabase()),
            $this->getRender()
        );
    }

    public function getEditorController()
    {
        return new EditorController(
            new PreguntaModel($this->getDatabase()),
            $this->getReporteModel(),
            $this->getRender()
        );
    }

    public function getAdminController()
    {
        return new AdminController(
            new EstadisticaModel($this->getDatabase()),
            new PreguntaEstadisticaModel($this->getDatabase()),
            new PreguntaModel($this->getDatabase()),
            $this->getReporteModel(),
            new CargaCsvModel($this->getDatabase(), new PreguntaModel($this->getDatabase())),
            new SolicitudEditorModel($this->getDatabase()),
            $this->getRender()
        );
    }

    public function getPreguntaController()
    {
        return new PreguntaController(
            $this->getReporteModel(),
            new PreguntaModel($this->getDatabase()),
            $this->getRender()
        );
    }

    public function getRegistroController()
    {
        return new RegistroController(
            $this->getUsuarioModel(),
            $this->getUbicacionModel(),
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

    private function getReporteModel()
    {
        return new ReporteModel($this->getDatabase());
    }

    private function getPartidaModel()
    {
        return new PartidaModel(
            $this->getDatabase()
        );
    }

    private function getUbicacionModel()
    {
        return new UbicacionModel(
            new PaisModel($this->getDatabase())
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
        return new Router($this, "home", "show", self::CONTROLLERS_PUBLICOS, self::ROLES_POR_CONTROLLER);
    }

    private function getPerfilModel()
    {
        return new PerfilModel($this->getDatabase());
    }

    public function getPerfilController()
    {
        return new PerfilController(
            $this->getPerfilModel(),
            new FotoPerfilModel(),
            $this->getUbicacionModel(),
            new RankingModel($this->getDatabase()),
            $this->getPartidaModel(),
            new SolicitudEditorModel($this->getDatabase()),
            $this->getRender()
        );
    }
}
