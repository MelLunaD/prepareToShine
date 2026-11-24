<?php

namespace app\controller;

use Request;
use Sesion;

// Página 404: la muestra el Router cuando la dirección no lleva a ningún controller o método,
// y Apache (ErrorDocument del .htaccess) cuando piden un archivo que no existe.
// Es pública: también la ve quien no inició sesión.
class ErrorController
{
    private $render;

    public function __construct($render)
    {
        $this->render = $render;
    }

    public function noEncontrada()
    {
        http_response_code(404);

        $usuario = Sesion::usuario();
        $tema = $this->temaDeLaPagina($usuario);

        $data = [
            "tema" => $tema,
            "palabraFase" => $tema === "souls" ? "Die" : "Shine",
            "sesion" => $usuario,
            "esSouls" => $tema === "souls",
            "esShine" => $tema === "shine",
            "esPanel" => $tema === "editor",
            "volver" => $this->adondeVolver($usuario, $tema),
            // El "perdiste" del modo: en Die, el de "YOU DIED".
            "sonidos" => "perder",
        ];

        $this->render->renderiza("noEncontrada", $data);
    }

    // Shine o Die según el modo en el que venía: el ?modo=die de la dirección rota o el de la
    // página desde la que llegó (por ejemplo, un link roto del lobby de Die). Adentro de los
    // paneles de moderar y administrar se usa su estética neutra.
    private function temaDeLaPagina($usuario)
    {
        $ruta = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
        $esDelPanel = preg_match('#^/(admin|editor)(/|$)#i', $ruta) === 1;

        if ($usuario !== null && ($usuario["esAdmin"] || ($usuario["esEditor"] && $esDelPanel))) {
            return "editor";
        }

        $vieneDeDie = Request::get("modo") === "die"
            || strpos($_SERVER["HTTP_REFERER"] ?? "", "modo=die") !== false;

        return $vieneDeDie ? "souls" : "shine";
    }

    // El botón lleva a la "home" de cada uno: el login, el panel del admin, el panel de moderar
    // o el lobby del modo en el que estaba.
    private function adondeVolver($usuario, $tema)
    {
        if ($usuario === null) {
            return ["url" => "/login", "texto" => "Ir a iniciar sesión"];
        }
        if ($usuario["esAdmin"]) {
            return ["url" => "/admin", "texto" => "Volver al panel"];
        }
        if ($tema === "editor") {
            return ["url" => "/editor", "texto" => "Volver a moderar"];
        }
        if ($tema === "souls") {
            return ["url" => "/?modo=die", "texto" => "Volver a la hoguera"];
        }
        return ["url" => "/", "texto" => "Volver al inicio"];
    }
}
