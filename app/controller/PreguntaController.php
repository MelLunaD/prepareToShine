<?php

namespace app\controller;

use app\model\PreguntaModel;
use Redirect;
use Request;
use Sesion;

// Acciones del jugador sobre una pregunta:
//   POST /pregunta/reportar   pregunta_id + motivo  ->  JSON {"ok": bool, "mensaje": "...", "restantes": n}
//        (la llama public/js/reportar.js con fetch, AJAX, sin recargar la página)
//   GET  /pregunta/sugerir?modo=die   formulario "Sugerir pregunta" (categorías del modo)
//   POST /pregunta/sugerir            la guarda en 'revision' para que la revise un editor
class PreguntaController
{
    private $reporteModel;
    private $preguntaModel;
    private $render;

    public function __construct($reporteModel, $preguntaModel, $render)
    {
        $this->reporteModel = $reporteModel;
        $this->preguntaModel = $preguntaModel;
        $this->render = $render;
    }

    public function show()
    {
        Redirect::to("/pregunta/sugerir");
    }

    public function sugerir()
    {
        $usuario = Sesion::usuario();
        $modo = Request::post("modo", Request::get("modo")) === "die" ? "die" : "shine";

        // El editor no sugiere: crea la pregunta directo desde su panel.
        if ($usuario["esEditor"]) {
            Redirect::to("/editor/nueva");
        }

        $restantes = $this->preguntaModel->sugerenciasRestantes($usuario["id"]);
        $datos = $this->preguntaModel->datosDelFormulario([]);
        $errores = [];

        // El límite semanal lo controla el servidor, aunque alguien mande el formulario a mano.
        if (Request::esPost() && $restantes > 0) {
            $datos = $this->preguntaModel->datosDelFormulario($_POST);
            $errores = $this->preguntaModel->validar($datos, 0, false);

            if (!$errores) {
                $this->preguntaModel->sugerir($datos, $usuario["id"]);
                Sesion::flash("sugerir", "¡Gracias! Tu pregunta llegó a los editores. Si la aprueban, va a salir en el juego.");
                Redirect::to("/pregunta/sugerir?modo=$modo");
            }
        }

        $categorias = $this->preguntaModel->categoriasPorModo();
        $esDie = $modo === "die";

        $this->render->renderiza("sugerir", EditorController::camposDelFormulario($datos, [$modo => $categorias[$modo]]) + [
            "tema" => $esDie ? "souls" : "shine",
            "palabraFase" => $esDie ? "Die" : "Shine",
            "sesion" => $usuario,
            "modo" => $modo,
            "esDie" => $esDie,
            "errores" => $errores,
            "mensaje" => Sesion::tomarFlash("sugerir"),
            "restantes" => $restantes,
            "puedeSugerir" => $restantes > 0,
            "unaRestante" => $restantes === 1,
            "limiteSemanal" => PreguntaModel::SUGERIDAS_POR_SEMANA,
        ]);
    }

    public function reportar()
    {
        if (!Request::esPost()) {
            $this->responderJson(["ok" => false, "mensaje" => "Usá POST."], 405);
            return;
        }

        $resultado = $this->reporteModel->reportar(
            Sesion::usuario()["id"],
            (int)Request::post("pregunta_id", 0),
            (string)Request::post("motivo", "")
        );

        $this->responderJson($resultado, $resultado["ok"] ? 200 : 422);
    }

    private function responderJson(array $datos, $codigo)
    {
        http_response_code($codigo);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    }
}
