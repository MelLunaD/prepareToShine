<?php

require_once __DIR__ . "/../vendor/mustache/Mustache/Autoloader.php";
Mustache_Autoloader::register(__DIR__ . "/../vendor/mustache");

class MustacheRender
{
    private $mustache;
    private $viewPath;

    public function __construct()
    {
        $this->viewPath = dirname(__DIR__) . "/app/view";
        $this->mustache = new Mustache_Engine([
            "loader" => new Mustache_Loader_FilesystemLoader($this->viewPath, ["extension" => ".mustache"])
        ]);
    }

    public function renderiza($view, $data = [])
    {
        // Datos que necesita el layout (header y footer) si el controller no los manda:
        // por defecto, el tema colorido "shine".
        $data += ["tema" => "shine", "palabraFase" => "Shine"];

        echo $this->mustache->render("header", $data);
        echo $this->mustache->render($view . "View", $data);
        echo $this->mustache->render("footer", $data);
    }

    // Devuelve una vista sola, sin header ni footer, como texto (por ejemplo, el cuerpo de un mail).
    public function generar($view, $data = [])
    {
        return $this->mustache->render($view, $data);
    }
}
