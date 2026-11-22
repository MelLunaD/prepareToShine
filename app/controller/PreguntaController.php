<?php

namespace app\controller;

use Request;
use Sesion;

// Acciones del jugador sobre una pregunta. Responden JSON: las llama public/js/reportar.js
// con fetch (AJAX), sin recargar la página.
//   POST /pregunta/reportar   pregunta_id + motivo  ->  {"ok": bool, "mensaje": "...", "restantes": n}
class PreguntaController
{
    private $reporteModel;

    public function __construct($reporteModel)
    {
        $this->reporteModel = $reporteModel;
    }

    public function show()
    {
        $this->responderJson(["ok" => false, "mensaje" => "Usá POST /pregunta/reportar."], 405);
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
