<?php

namespace app\controller;

use app\model\PartidaModel;
use Request;
use Sesion;

// Lobby: como todo el sitio pide sesión, la home siempre es el lobby del jugador.
// Muestra Shine y Die; el CSS deja ver el panel del tema activo (.solo-shine / .solo-souls),
// así el panel de Die aparece recién con el secreto de los 5 clicks.
class HomeController
{
    const MODOS = ["shine", "die"];

    private $partidaModel;
    private $rankingModel;
    private $solicitudModel;
    private $render;

    public function __construct($partidaModel, $rankingModel, $solicitudModel, $render)
    {
        $this->partidaModel = $partidaModel;
        $this->rankingModel = $rankingModel;
        $this->solicitudModel = $solicitudModel;
        $this->render = $render;
    }

    public function show()
    {
        $aviso = $this->revisarSolicitud();
        $usuario = Sesion::usuario();

        // Fase visual: "shine" (colorida) o "souls" (oscura). La home arranca en shine y el paso
        // a souls lo dispara el secreto de public/js/secreto.js. Al volver de una partida de Die
        // (/?modo=die) se queda en souls.
        $data["tema"] = Request::get("modo") === "die" ? "souls" : "shine";
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = $usuario;
        $data["aviso"] = $aviso;

        // Si se le terminó el tiempo con la página cerrada, la partida se cierra acá:
        // el botón no puede ofrecer "Continuar" una partida que ya perdió.
        $enCurso = $this->partidaModel->enCurso($usuario["id"]);
        if ($enCurso !== null && $this->partidaModel->cerrarSiVencio($enCurso)) {
            $enCurso = null;
        }

        foreach (self::MODOS as $modo) {
            $data[$modo] = $this->datosDelModo($usuario["id"], $modo, $enCurso);
        }

        $this->render->renderiza("home", $data);
    }

    // Si tenía un "Quiero moderar" pendiente, mira si ya lo aprobaron: así ve "Moderar" en el
    // menú sin cerrar sesión. A quien no pidió nada le cuesta una sola consulta por sesión.
    private function revisarSolicitud()
    {
        if (!Sesion::hayQueRevisarSolicitud()) {
            return null;
        }

        $usuario = Sesion::usuario();
        $situacion = $this->solicitudModel->situacion($usuario["id"]);
        Sesion::revisarSolicitud($situacion["pendiente"]);

        if ($situacion["rol"] === $usuario["rol"]) {
            return null;
        }
        Sesion::cambiarRol($situacion["rol"]);
        return $situacion["rol"] === "editor"
            ? "¡Aprobaron tu solicitud! Ya sos moderador: lo encontrás en el menú, en \"Moderar\"."
            : null;
    }

    private function datosDelModo($usuarioId, $modo, $enCurso)
    {
        $datos = $this->rankingModel->resumen($usuarioId, $modo) + [
            "enCurso" => $enCurso !== null,
            "enCursoEsDeEsteModo" => $enCurso !== null && $enCurso["modo"] === $modo,
            "partidas" => array_map([$this, "formatearPartida"], $this->partidaModel->historial($usuarioId, $modo)),
            "almasEsperando" => null,
        ];

        if ($modo === "die") {
            $almas = $this->partidaModel->almasEsperando($usuarioId);
            if ($almas !== null) {
                $datos["almasEsperando"] = ["almas" => (int)$almas["almas"], "numero" => (int)$almas["numero"]];
            }
        }

        $datos["hayPartidas"] = count($datos["partidas"]) > 0;
        return $datos;
    }

    private function formatearPartida(array $partida)
    {
        $respondidas = (int)$partida["respondidas"];
        $murio = (int)$partida["errores"] > 0;

        if ($murio) {
            $resultado = "Cayó en la " . $respondidas;
        } elseif ($respondidas === PartidaModel::PREGUNTAS_POR_PARTIDA) {
            $resultado = "Completa";
        } else {
            $resultado = "Se retiró en la " . $respondidas;
        }

        return [
            "id" => $partida["id"],
            "fecha" => date("d/m H:i", strtotime($partida["fecha_inicio"])),
            "resultado" => $resultado,
            "puntaje" => (int)$partida["puntaje"],
            "almas" => (int)$partida["almas"] > 0 ? (int)$partida["almas"] : null,
        ];
    }
}
