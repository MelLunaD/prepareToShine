<?php

namespace app\controller;

use app\model\PartidaModel;
use app\model\ReporteModel;
use Redirect;
use Request;
use Sesion;

// Pantallas de la partida:
//   POST /partida/nueva       crea (o retoma) la partida del modo elegido en el lobby
//   GET  /partida/jugar       muestra lo que toque: cartas, pregunta o checkpoint
//   POST /partida/elegir      el jugador tocó una carta: sale la pregunta y corre el reloj
//   POST /partida/responder   corrige la respuesta
//   POST /partida/retirarse   Die: retirarse en un checkpoint con todo lo ganado
//   GET  /partida/resultado   fin de la partida
class PartidaController
{
    private $partidaModel;
    private $reporteModel;
    private $rankingModel;
    private $render;

    public function __construct($partidaModel, $reporteModel, $rankingModel, $render)
    {
        $this->partidaModel = $partidaModel;
        $this->reporteModel = $reporteModel;
        $this->rankingModel = $rankingModel;
        $this->render = $render;
    }

    public function show()
    {
        Redirect::to("/partida/jugar");
    }

    public function nueva()
    {
        if (!Request::esPost()) {
            Redirect::toIndex();
        }

        $modo = Request::post("modo") === "die" ? "die" : "shine";
        $this->partidaModel->crear($this->usuarioId(), $modo);

        Redirect::to("/partida/jugar");
    }

    public function jugar()
    {
        $partida = $this->partidaEnCurso();

        $pregunta = $this->partidaModel->preguntaActual($partida);
        if ($pregunta !== null) {
            $this->mostrarPregunta($partida, $pregunta);
            return;
        }

        if ($this->partidaModel->puedeRetirarse($partida) && Request::get("seguir") === null) {
            $this->mostrarCheckpoint($partida);
            return;
        }

        $this->mostrarCartas($partida);
    }

    public function elegir()
    {
        if (Request::esPost()) {
            $this->partidaModel->elegirCarta($this->partidaEnCurso());
        }

        Redirect::to("/partida/jugar");
    }

    public function responder()
    {
        if (!Request::esPost()) {
            Redirect::to("/partida/jugar");
        }

        $partida = $this->partidaEnCurso();
        $respuestaId = Request::post("respuesta");
        $resultado = $this->partidaModel->responder($partida, $respuestaId !== null && $respuestaId !== "" ? (int)$respuestaId : null);

        if ($resultado === null) {
            Redirect::to("/partida/jugar");
        }

        if ($resultado["terminada"]) {
            Redirect::to("/partida/resultado?id=" . $partida["id"]);
        }

        Sesion::flash("partida_acierto", $resultado);
        Redirect::to("/partida/jugar");
    }

    public function retirarse()
    {
        $partida = $this->partidaEnCurso();

        if (Request::esPost() && $this->partidaModel->retirarse($partida)) {
            Redirect::to("/partida/resultado?id=" . $partida["id"]);
        }

        Redirect::to("/partida/jugar");
    }

    public function resultado()
    {
        $partida = $this->partidaModel->resultado((int)Request::get("id", 0), $this->usuarioId());
        if ($partida === null) {
            Redirect::toIndex();
        }

        $esDie = $partida["modo"] === "die";
        $completa = $partida["respondidas"] === PartidaModel::PREGUNTAS_POR_PARTIDA && $partida["error"] === null;

        $data = $this->datosComunes($partida["modo"]);
        $data["puntaje"] = (int)$partida["puntaje"];
        $data["respondidas"] = $partida["respondidas"];
        $data["completa"] = $completa;
        $data["retiro"] = $partida["error"] === null && !$completa;
        $data["error"] = $partida["error"];
        $data["sin_responder"] = $partida["error"] !== null && $partida["error"]["respuesta_id"] === null;
        $data["almas"] = $esDie && (int)$partida["almas"] > 0 ? (int)$partida["almas"] : null;
        $data["urlLobby"] = $esDie ? "/?modo=die" : "/";
        $rangoNuevo = $this->rankingModel->subioDeRango($this->usuarioId(), $partida["modo"], $partida["id"]);
        $data["sonidos"] = $this->sonidosDelResultado($completa, $data["retiro"], $rangoNuevo !== null);
        $data["rangoNuevo"] = $this->datosDelRangoNuevo($rangoNuevo, $esDie);
        $data += $this->datosDelReporte($partida["id"]);

        $this->render->renderiza("resultado", $data);
    }

    // ---------- Pantallas ----------

    private function mostrarCartas(array $partida)
    {
        $data = $this->datosDeLaPartida($partida);
        $data["cartas"] = range(1, $this->partidaModel->cantidadDeCartas($partida["modo"]));
        $data["acierto"] = Sesion::tomarFlash("partida_acierto");
        // Sonido del acierto (y de las almas recuperadas). Si viene de "Seguir" en una hoguera
        // no suena otra vez: ya sonó en la pantalla de la hoguera.
        if ($data["acierto"] && Request::get("seguir") === null) {
            $data["sonidos"] = !empty($data["acierto"]["almas_recuperadas"]) ? "correcta almas" : "correcta";
        }
        $data += $this->datosDelReporte($partida["id"]);

        $this->render->renderiza("cartas", $data);
    }

    private function mostrarPregunta(array $partida, array $pregunta)
    {
        $data = $this->datosDeLaPartida($partida);
        $data["numero"] = (int)$pregunta["numero"];
        $data["pregunta"] = $pregunta;
        $data["categoriaTitulo"] = $pregunta["juego"] !== null && $pregunta["juego"] !== ""
            ? $pregunta["categoria"] . " (" . $pregunta["juego"] . ")"
            : $pregunta["categoria"];
        $data["segundosRestantes"] = (int)$pregunta["segundos_restantes"];
        $data["segundosTotales"] = PartidaModel::SEGUNDOS_POR_PREGUNTA;
        // Recién elegida: la carta muestra la categoría unos segundos antes de la pregunta.
        $data["segundosParaEmpezar"] = (int)$pregunta["segundos_para_empezar"];

        $this->render->renderiza("pregunta", $data);
    }

    private function mostrarCheckpoint(array $partida)
    {
        $data = $this->datosDeLaPartida($partida);
        $data["sonidos"] = "correcta hoguera";
        $data += $this->datosDelReporte($partida["id"]);
        $this->render->renderiza("checkpoint", $data);
    }

    // ---------- Datos ----------

    // Sonidos del final, en orden (sonidos.js elige la versión Shine o Die de cada uno):
    // ganar / retirarse con las almas / errar y perder; y si subió de rango, el de rango al final.
    private function sonidosDelResultado($completa, $retiro, $subioDeRango)
    {
        if ($completa) {
            $sonidos = "ganar";
        } elseif ($retiro) {
            $sonidos = "almas";
        } else {
            $sonidos = "incorrecta perder";
        }

        if ($subioDeRango) {
            $sonidos .= " subir-rango";
        }
        return $sonidos;
    }

    // Cartel (modal) que aparece en el resultado cuando la partida le hizo subir de rango.
    // Los textos cambian según el modo, como el resto de la pantalla.
    private function datosDelRangoNuevo($rango, $esDie)
    {
        if ($rango === null) {
            return null;
        }
        return [
            "titulo" => $esDie ? "Nuevo rango alcanzado" : "¡Subiste de rango!",
            "nombre" => $rango["nombre"],
            "colorRango" => $rango["color"],
            "rangoBrilla" => (bool)$rango["brilla"],
            "iconoRango" => !empty($rango["icono"]) ? "/public/img/rangos/" . $rango["icono"] : null,
            "texto" => $esDie ? "Tus almas de esta semana te llevaron más lejos." : "Con los puntos de esta semana llegaste a un rango nuevo.",
            "boton" => $esDie ? "Continuar" : "¡Genial!",
        ];
    }

    // Botón "Reportar pregunta": solo con la pregunta que el jugador ACABA de responder,
    // en las pantallas de después de responder (cartas, hoguera y resultado). Nunca en la
    // pantalla de la pregunta en curso.
    private function datosDelReporte($partidaId)
    {
        $pregunta = $this->reporteModel->ultimaRespondida($partidaId, $this->usuarioId());
        if ($pregunta === null) {
            return ["reporte" => null];
        }

        $restantes = $this->reporteModel->restantes($this->usuarioId());
        $motivos = [];
        foreach (ReporteModel::MOTIVOS as $valor => $nombre) {
            $motivos[] = ["valor" => $valor, "nombre" => $nombre];
        }

        return [
            "usaReporte" => true,
            "reporte" => [
                "preguntaId" => (int)$pregunta["id"],
                "texto" => $pregunta["texto"],
                "yaReportada" => (bool)$pregunta["ya_reportada"],
                "restantes" => $restantes,
                "sinReportes" => $restantes === 0,
                "unoRestante" => $restantes === 1,
                "motivos" => $motivos,
            ],
        ];
    }

    // Tema según el modo: una partida de Die siempre se ve Souls, aunque se recargue.
    private function datosComunes($modo)
    {
        $data["tema"] = $modo === "die" ? "souls" : "shine";
        $data["palabraFase"] = $modo === "die" ? "Die" : "Shine";
        $data["sesion"] = Sesion::usuario();
        $data["esDie"] = $modo === "die";
        $data["usaPartida"] = true;
        return $data;
    }

    private function datosDeLaPartida(array $partida)
    {
        $puntos = $this->partidaModel->puntosEnJuego($partida);
        $respondidas = $this->partidaModel->preguntasRespondidas($partida);

        $data = $this->datosComunes($partida["modo"]);
        // Música de fondo en cartas, pregunta y hoguera; en el resultado no, así se escucha
        // bien el final (victoria, YOU DIED...).
        $data["musica"] = true;
        $data["numero"] = $respondidas + 1;
        $data["totalPreguntas"] = PartidaModel::PREGUNTAS_POR_PARTIDA;
        $data["puntos"] = $puntos["total"];
        $data["asegurados"] = $data["esDie"] ? $puntos["asegurados"] : null;
        $data["proximoCheckpoint"] = $this->proximoCheckpoint($partida, $respondidas);

        $almas = $data["esDie"] ? $this->partidaModel->almasEsperando($partida["usuario_id"]) : null;
        $data["almasEsperando"] = $almas !== null && (int)$almas["partida_id"] !== (int)$partida["id"] && (int)$almas["numero"] > $respondidas
            ? ["almas" => (int)$almas["almas"], "numero" => (int)$almas["numero"]]
            : null;

        return $data;
    }

    private function proximoCheckpoint(array $partida, $respondidas)
    {
        if ($partida["modo"] !== "die") {
            return null;
        }
        foreach (PartidaModel::CHECKPOINTS_DIE as $checkpoint) {
            if ($checkpoint > $respondidas) {
                return $checkpoint;
            }
        }
        return null;
    }

    // La partida en curso del jugador. Si se le terminó el tiempo con la página cerrada,
    // se cierra acá y va directo al resultado. Sin partida en curso, vuelve al lobby.
    private function partidaEnCurso()
    {
        $partida = $this->partidaModel->enCurso($this->usuarioId());
        if ($partida === null) {
            Redirect::toIndex();
        }

        if ($this->partidaModel->cerrarSiVencio($partida)) {
            Redirect::to("/partida/resultado?id=" . $partida["id"]);
        }

        return $partida;
    }

    private function usuarioId()
    {
        return Sesion::usuario()["id"];
    }
}
