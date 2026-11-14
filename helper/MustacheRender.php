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
        $loader = new Mustache_Loader_FilesystemLoader($this->viewPath, ["extension" => ".mustache"]);
        // Las vistas pueden incluir pedazos de otras con {{> nombre}} (por ejemplo, lobbyModo).
        $this->mustache = new Mustache_Engine([
            "loader" => $loader,
            "partials_loader" => $loader,
        ]);
    }

    public function renderiza($view, $data = [])
    {
        // Datos que necesita el layout (header y footer) si el controller no los manda:
        // por defecto, el tema colorido "shine".
        $data += ["tema" => "shine", "palabraFase" => "Shine"];

        // Versión de los estilos y scripts propios (?v=...): cambia con cada deploy, así el
        // navegador no sigue usando el estilos.css o el .js viejo que tenía guardado.
        $data += ["version" => $this->versionDeAssets()];

        echo $this->mustache->render("header", $data);
        echo $this->mustache->render($view . "View", $data);
        echo $this->mustache->render("footer", $data);
    }

    // La fecha de modificación más nueva entre los archivos propios de public/css y public/js.
    private function versionDeAssets()
    {
        $archivos = array_merge(glob(dirname(__DIR__) . "/public/css/*.css"), glob(dirname(__DIR__) . "/public/js/*.js"));
        return $archivos ? max(array_map("filemtime", $archivos)) : 0;
    }

    // Devuelve una vista sola, sin header ni footer, como texto (por ejemplo, el cuerpo de un mail).
    public function generar($view, $data = [])
    {
        return $this->mustache->render($view, $data);
    }
}
