<?php

namespace app\controller;

use app\model\PreguntaModel;
use app\model\ReporteModel;
use Redirect;
use Request;
use Sesion;

// Panel del editor ("Moderar preguntas"). Solo entra el rol editor: lo controla el Router
// con Configuration::ROLES_POR_CONTROLLER. Tiene tres pestañas: Reportadas, Sugeridas
// y Nueva pregunta. El editor no ve el listado de todas las preguntas.
class EditorController
{
    const PESTANIAS = [
        "reportadas" => ["titulo" => "Reportadas", "url" => "/editor"],
        "sugeridas" => ["titulo" => "Sugeridas", "url" => "/editor/sugeridas"],
        "nueva" => ["titulo" => "Nueva pregunta", "url" => "/editor/nueva"],
    ];

    private $preguntaModel;
    private $reporteModel;
    private $render;

    public function __construct($preguntaModel, $reporteModel, $render)
    {
        $this->preguntaModel = $preguntaModel;
        $this->reporteModel = $reporteModel;
        $this->render = $render;
    }

    // Pestaña Reportadas: preguntas en cuarentena, la más vieja primero.
    public function show()
    {
        $reportadas = array_map([$this, "formatearReportada"], $this->reporteModel->reportadas());
        $this->mostrar("reportadas", [
            "reportadas" => $reportadas,
            "hayReportadas" => count($reportadas) > 0,
        ]);
    }

    // "Dar de baja" o "Es válida" desde la tarjeta de una reportada.
    public function resolver()
    {
        if (Request::esPost()) {
            $accion = Request::post("accion");
            $preguntaId = (int)Request::post("pregunta_id", 0);

            if (in_array($accion, ["baja", "valida"], true)) {
                $resuelta = $this->reporteModel->resolver($preguntaId, $accion, Sesion::usuario()["id"]);
                Sesion::flash("editor", !$resuelta
                    ? "Esa pregunta ya la había resuelto otro editor."
                    : ($accion === "baja"
                        ? "Pregunta #$preguntaId dada de baja. Ya no sale en el juego."
                        : "Pregunta #$preguntaId marcada como válida. Vuelve al juego."));
            }
        }

        Redirect::to("/editor");
    }

    // Editar una pregunta reportada (y reactivarla al guardar).
    public function editar()
    {
        $datos = $this->preguntaModel->paraEditar((int)Request::get("id", 0));
        if ($datos === null) {
            Redirect::to("/editor");
        }

        $this->mostrarFormulario($datos, [], $datos["id"]);
    }

    public function guardar()
    {
        $id = (int)Request::post("id", 0);
        $original = $this->preguntaModel->paraEditar($id);
        if (!Request::esPost() || $original === null) {
            Redirect::to("/editor");
        }

        $datos = $this->preguntaModel->datosDelFormulario($_POST);
        $errores = $this->preguntaModel->validar($datos, $id);
        if ($errores) {
            $this->mostrarFormulario($datos, $errores, $id);
            return;
        }

        $editorId = Sesion::usuario()["id"];
        $this->preguntaModel->actualizar($id, $datos, $editorId);

        // Si estaba reportada, el reporte tenía razón y ya se corrigió: vuelve al juego.
        if ($original["estado"] === "cuarentena") {
            $this->reporteModel->resolver($id, "corregida", $editorId);
            Sesion::flash("editor", "Pregunta #$id corregida. Vuelve al juego.");
        }

        Redirect::to("/editor");
    }

    // Pestaña Sugeridas (la revisión llega en el paso 3 del rol).
    public function sugeridas()
    {
        $this->mostrar("sugeridas", []);
    }

    public function nueva()
    {
        $this->mostrarFormulario($this->preguntaModel->datosDelFormulario([]), [], null);
    }

    public function crear()
    {
        if (!Request::esPost()) {
            Redirect::to("/editor/nueva");
        }

        $editorId = Sesion::usuario()["id"];
        // El límite semanal lo controla el servidor: aunque alguien mande el formulario a mano.
        if ($this->preguntaModel->creacionesRestantes($editorId) === 0) {
            Redirect::to("/editor/nueva");
        }

        $datos = $this->preguntaModel->datosDelFormulario($_POST);
        $errores = $this->preguntaModel->validar($datos);

        if ($errores) {
            $this->mostrarFormulario($datos, $errores, null);
            return;
        }

        $id = $this->preguntaModel->crear($datos, $editorId);
        Sesion::flash("editor", "Pregunta #$id creada. Ya está activa en el juego.");
        Redirect::to("/editor/nueva");
    }

    // $editandoId: null para "Nueva pregunta", o el id de la pregunta que se corrige.
    private function mostrarFormulario(array $datos, array $errores, $editandoId)
    {
        $categorias = $this->preguntaModel->categoriasPorModo();
        $restantes = $this->preguntaModel->creacionesRestantes(Sesion::usuario()["id"]);
        $editando = $editandoId !== null;

        $this->mostrar($editando ? "reportadas" : "nueva", [
            "enFormulario" => true,
            "editandoId" => $editandoId,
            "tituloFormulario" => $editando ? "Corregir pregunta #$editandoId" : "Nueva pregunta",
            "accionFormulario" => $editando ? "/editor/guardar" : "/editor/crear",
            "botonFormulario" => $editando ? "Guardar y reactivar" : "Crear pregunta",
            "mostrarCupo" => !$editando,
            "restantes" => $restantes,
            // Corregir no gasta cupo: el límite de 15 es para preguntas nuevas.
            "puedeCrear" => $editando || $restantes > 0,
            "unaRestante" => $restantes === 1,
            "limiteSemanal" => PreguntaModel::CREADAS_POR_SEMANA,
            "errores" => $errores,
            "texto" => $datos["texto"],
            "juego" => $datos["juego"],
            "largoTexto" => PreguntaModel::LARGO_TEXTO,
            "largoJuego" => PreguntaModel::LARGO_JUEGO,
            "largoRespuesta" => PreguntaModel::LARGO_RESPUESTA,
            "categoriasShine" => $this->marcarCategoria($categorias["shine"], $datos["categoria_id"]),
            "categoriasDie" => $this->marcarCategoria($categorias["die"], $datos["categoria_id"]),
            "respuestas" => $this->respuestasParaLaVista($datos),
            "dificultades" => $this->dificultadesParaLaVista($datos["dificultad"]),
        ]);
    }

    private function mostrar($pestaniaActiva, array $datos)
    {
        $pendientes = $this->preguntaModel->pendientes();

        $pestanias = [];
        foreach (self::PESTANIAS as $clave => $pestania) {
            $pestanias[] = $pestania + [
                "activa" => $clave === $pestaniaActiva,
                "cantidad" => $pendientes[$clave] ?? null,
            ];
        }

        $data = $datos + [
            // Estética neutra: la del juego (fuentes, bordes, botones) con colores tranquilos.
            "tema" => "editor",
            "palabraFase" => "Shine",
            "sesion" => Sesion::usuario(),
            "pestanias" => $pestanias,
            "pendientes" => $pendientes,
            "en" . ucfirst($pestaniaActiva) => !isset($datos["enFormulario"]),
            "mensaje" => Sesion::tomarFlash("editor"),
        ];

        $this->render->renderiza("editor", $data);
    }

    private function formatearReportada(array $pregunta)
    {
        $letras = PreguntaModel::LETRAS;
        foreach ($pregunta["respuestas"] as $i => &$respuesta) {
            $respuesta["letra"] = $letras[$i];
            $respuesta["correcta"] = (int)$respuesta["es_correcta"] === 1;
        }
        unset($respuesta);

        foreach ($pregunta["reportes"] as &$reporte) {
            $reporte["motivo"] = $this->reporteModel->nombreDelMotivo($reporte["motivo"]);
            $reporte["fecha"] = date("d/m H:i", strtotime($reporte["fecha"]));
        }
        unset($reporte);

        foreach ($pregunta["historial"] as &$revision) {
            $revision["motivo"] = $this->reporteModel->nombreDelMotivo($revision["motivo"]);
            $revision["fecha"] = $revision["fecha_revision"] ? date("d/m/Y", strtotime($revision["fecha_revision"])) : "";
            $revision["resultado"] = $revision["estado"] === "rechazado" ? "la marcó como válida" : "le dio la razón al reporte";
        }
        unset($revision);

        $mostrada = (int)$pregunta["veces_mostrada"];
        $pregunta["porcentaje"] = $mostrada > 0 ? round((int)$pregunta["veces_acertada"] * 100 / $mostrada) : null;
        $pregunta["categoriaTitulo"] = $pregunta["juego"] ? $pregunta["categoria"] . " (" . $pregunta["juego"] . ")" : $pregunta["categoria"];
        $pregunta["modo"] = $pregunta["fase"] === "die" ? "Die" : "Shine";
        $pregunta["dificultad"] = PreguntaModel::DIFICULTADES[$pregunta["dificultad"]] ?? $pregunta["dificultad"];
        $pregunta["cantidadReportes"] = count($pregunta["reportes"]);
        $pregunta["variosReportes"] = count($pregunta["reportes"]) > 1;
        $pregunta["hayHistorial"] = count($pregunta["historial"]) > 0;
        return $pregunta;
    }

    private function marcarCategoria(array $categorias, $elegida)
    {
        return array_map(fn($c) => $c + ["elegida" => $c["id"] === $elegida], $categorias);
    }

    private function respuestasParaLaVista(array $datos)
    {
        $respuestas = [];
        foreach ($datos["respuestas"] as $i => $texto) {
            $respuestas[] = [
                "indice" => $i,
                "letra" => PreguntaModel::LETRAS[$i],
                "texto" => $texto,
                "correcta" => $datos["correcta"] === $i,
            ];
        }
        return $respuestas;
    }

    private function dificultadesParaLaVista($elegida)
    {
        $dificultades = [];
        foreach (PreguntaModel::DIFICULTADES as $valor => $nombre) {
            $dificultades[] = ["valor" => $valor, "nombre" => $nombre, "elegida" => $valor === $elegida];
        }
        return $dificultades;
    }
}
