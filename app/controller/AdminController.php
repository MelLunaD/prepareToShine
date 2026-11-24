<?php

namespace app\controller;

use app\model\CargaCsvModel;
use app\model\EstadisticaModel;
use app\model\PartidaModel;
use app\model\PreguntaEstadisticaModel;
use app\model\PreguntaModel;
use app\model\ReporteModel;
use Grafico;
use Pdf;
use Redirect;
use Request;
use Sesion;

// Panel del administrador. Solo entra el rol admin: lo controla el Router con
// Configuration::ROLES_POR_CONTROLLER. El administrador no juega: al entrar al sitio
// lo manda acá el HomeController.
// Pestañas: Estadísticas (números y gráficos filtrables por período), Preguntas (cómo funcionan
// las preguntas de cada modo) y Solicitudes ("Quiero moderar").
class AdminController
{
    const PESTANIAS = [
        "estadisticas" => ["titulo" => "Estadísticas", "url" => "/admin"],
        "preguntas" => ["titulo" => "Preguntas", "url" => "/admin/preguntas"],
        "solicitudes" => ["titulo" => "Solicitudes", "url" => "/admin/solicitudes"],
    ];

    // Lo que necesita el servidor para lo que viene: CpChart dibuja los gráficos del PDF con GD,
    // y Dompdf usa DOM y mbstring para armar el PDF.
    const EXTENSIONES = [
        "gd" => "GD (dibuja los gráficos del PDF)",
        "dom" => "DOM (lo usa Dompdf para leer el HTML)",
        "mbstring" => "mbstring (acentos y eñes en el PDF)",
    ];

    // Colores de los gráficos del PDF (los mismos tonos que en pantalla, un poco más oscuros
    // para que se lean sobre papel blanco).
    const COLOR_PARTIDAS = [214, 158, 30];
    const COLOR_PAISES = [61, 120, 216];
    const COLOR_EDAD = [46, 160, 67];
    const TRAMOS_POR_FILA = 8;

    // Qué es cada barra del gráfico de partidas según el período, para que se entienda solo.
    const TIEMPO = [
        "dia" => ["titulo" => "Partidas jugadas por hora", "ejeX" => "Hora del día", "tramo" => "Hora"],
        "semana" => ["titulo" => "Partidas jugadas por día", "ejeX" => "Día de la semana", "tramo" => "Día"],
        "mes" => ["titulo" => "Partidas jugadas por día", "ejeX" => "Día del mes", "tramo" => "Día"],
        "anio" => ["titulo" => "Partidas jugadas por mes", "ejeX" => "Mes", "tramo" => "Mes"],
        "todo" => ["titulo" => "Partidas jugadas por mes", "ejeX" => "Mes y año", "tramo" => "Mes"],
    ];
    const COLORES_SEXO = [[214, 158, 30], [61, 120, 216], [150, 155, 165]];

    private $estadisticaModel;
    private $preguntaEstadisticaModel;
    private $preguntaModel;
    private $reporteModel;
    private $cargaCsvModel;
    private $solicitudModel;
    private $render;

    public function __construct($estadisticaModel, $preguntaEstadisticaModel, $preguntaModel, $reporteModel, $cargaCsvModel, $solicitudModel, $render)
    {
        $this->cargaCsvModel = $cargaCsvModel;
        $this->estadisticaModel = $estadisticaModel;
        $this->preguntaEstadisticaModel = $preguntaEstadisticaModel;
        $this->preguntaModel = $preguntaModel;
        $this->reporteModel = $reporteModel;
        $this->solicitudModel = $solicitudModel;
        $this->render = $render;
    }

    // Pestaña Estadísticas. Sin JavaScript, el filtro de período recarga la página con ?periodo=.
    // Con JavaScript (public/js/admin.js) pide /admin/datos y cambia todo sin recargar.
    public function show()
    {
        $resumen = $this->estadisticaModel->resumen(Request::get("periodo", "semana"));

        $this->mostrar("estadisticas", $this->datosDeEstadisticas($resumen) + [
            "periodos" => $this->periodos($resumen["periodo"]),
            "periodoActual" => $resumen["periodo"],
            "tituloTiempo" => self::TIEMPO[$resumen["periodo"]]["titulo"],
            // Los datos de los gráficos van en la página como JSON, para que Chart.js los dibuje
            // apenas carga (JSON_HEX_TAG: un "</script>" en un nombre no puede cortar la etiqueta).
            "graficosJson" => json_encode($this->graficos($resumen), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
            "usaAdmin" => true,
        ]);
    }

    // AJAX del filtro de período:
    //   GET /admin/datos?periodo=mes
    //   ->  {"periodo": "mes", "tarjetas": "<html>", "aciertos": "<html>", "graficos": {...}}
    // El HTML lo arma Mustache (el mismo partial de la pantalla); los gráficos los actualiza Chart.js.
    public function datos()
    {
        $resumen = $this->estadisticaModel->resumen(Request::get("periodo", "semana"));
        $datos = $this->datosDeEstadisticas($resumen);

        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "periodo" => $resumen["periodo"],
            "tarjetas" => $this->render->generar("adminEstadisticas", $datos),
            "aciertos" => $this->render->generar("adminAciertos", $datos),
            "graficos" => $this->graficos($resumen),
        ], JSON_UNESCAPED_UNICODE);
    }

    // "Descargar PDF": las mismas estadísticas del período, con los gráficos dibujados en el
    // servidor (CpChart, helper/Grafico.php) y el PDF armado con Dompdf (helper/Pdf.php).
    // Los datos salen del mismo EstadisticaModel::resumen() que usa la pantalla.
    public function pdf()
    {
        $resumen = $this->estadisticaModel->resumen(Request::get("periodo", "semana"));
        $datos = $this->datosDeEstadisticas($resumen);
        $periodo = $resumen["periodo"];

        $tiempo = $resumen["partidasEnElTiempo"];
        $tramos = [];
        foreach ($tiempo["etiquetas"] as $i => $etiqueta) {
            if ($tiempo["valores"][$i] > 0) {
                $tramos[] = ["etiqueta" => $etiqueta, "valor" => $tiempo["valores"][$i]];
            }
        }

        $html = $this->render->generar("adminPdf", $datos + [
            "generado" => date("d/m/Y H:i"),
            "admin" => Sesion::usuario()["usuario"],
            "tituloTiempo" => self::TIEMPO[$periodo]["titulo"],
            "graficoTiempo" => Grafico::barras($tiempo["etiquetas"], $tiempo["valores"], self::COLOR_PARTIDAS,
                700, 280, false, self::TIEMPO[$periodo]["ejeX"], "Partidas"),
            "filasTramos" => array_map(fn($fila) => ["tramos" => $fila], array_chunk($tramos, self::TRAMOS_POR_FILA)),
            "hayTramos" => count($tramos) > 0,
            "nombreTramo" => self::TIEMPO[$periodo]["tramo"],
            "totalPartidas" => $tiempo["total"],
            "demografia" => [
                $this->demografia("Jugadores por país", "País", $resumen["porPais"],
                    Grafico::barras($resumen["porPais"]["etiquetas"], $resumen["porPais"]["valores"], self::COLOR_PAISES, 380, 240, true, null, "Jugadores")),
                $this->demografia("Jugadores por sexo", "Sexo", $resumen["porSexo"],
                    Grafico::anillo($resumen["porSexo"]["etiquetas"], $resumen["porSexo"]["valores"], self::COLORES_SEXO, 380, 200)),
                $this->demografia("Jugadores por edad", "Edad (años)", $resumen["porEdad"],
                    Grafico::barras($resumen["porEdad"]["etiquetas"], $resumen["porEdad"]["valores"], self::COLOR_EDAD, 380, 230, false, "Edad (años)", "Jugadores")),
            ],
        ]);

        Pdf::descargar($html, "estadisticas-" . $periodo . "-" . date("Y-m-d") . ".pdf");
    }

    // Pestaña Preguntas: números y gráficos de las preguntas de un modo.
    // Sin JavaScript, Shine / Die recarga la página con ?modo=. Con JavaScript
    // (public/js/adminPreguntas.js) pide /admin/preguntasDatos y cambia todo sin recargar.
    public function preguntas()
    {
        $resumen = $this->preguntaEstadisticaModel->resumen(Request::get("modo", "shine"));

        $this->mostrar("preguntas", $this->datosDePreguntas($resumen) + [
            "modos" => $this->modos($resumen["modo"]),
            "graficosJson" => json_encode($this->graficosDePreguntas($resumen), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
            "modoActual" => $resumen["modo"],
            "notaDificultad" => $this->notaDificultad($resumen),
            "usaAdminPreguntas" => true,
            "usaBuscador" => true,
        ]);
    }

    // Buscador de la pestaña Preguntas (AJAX, el mismo de Mel en el panel del editor):
    //   GET /admin/buscar?q=jefe final&fase=die  ->  {"resultados": [{id, texto, categoria, estado...}]}
    // A diferencia del editor, el administrador busca entre todas y cada resultado lleva a su página.
    public function buscar()
    {
        $fase = Request::get("fase");
        $resultados = $this->preguntaModel->buscar(
            (string)Request::get("q", ""),
            0,
            in_array($fase, ["shine", "die"], true) ? $fase : null
        );

        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["resultados" => $resultados], JSON_UNESCAPED_UNICODE);
    }

    // Página de una pregunta: sus datos, sus respuestas (cuántas veces eligieron cada una),
    // los gráficos de cómo le fue y los reportes que tuvo. Desde acá se edita y se da de baja.
    public function pregunta()
    {
        $detalle = $this->preguntaEstadisticaModel->detalle((int)Request::get("id", 0));
        if ($detalle === null) {
            Redirect::to("/admin/preguntas");
        }

        $p = $detalle["pregunta"];
        $t = $detalle["totales"];
        $graficos = [
            "nivel" => $detalle["porNivel"],
            "edad" => $detalle["porEdad"],
            "tiempo" => $detalle["enElTiempo"],
        ];

        $this->mostrar("preguntas", [
            "id" => (int)$p["id"],
            "texto" => $p["texto"],
            "categoriaTitulo" => $p["juego"] ? $p["categoria"] . " (" . $p["juego"] . ")" : $p["categoria"],
            "modo" => PreguntaEstadisticaModel::MODOS[$p["fase"]],
            "fase" => $p["fase"],
            "estado" => $p["estado"],
            "estadoNombre" => PreguntaModel::NOMBRE_ESTADO[$p["estado"]],
            // Qué botones de estado van, según cómo está la pregunta.
            "puedeDarDeBaja" => in_array($p["estado"], ["activa", "cuarentena"], true),
            "textoActivar" => ["cuarentena" => "Es válida: activar", "revision" => "Aprobar", "inactiva" => "Activar otra vez"][$p["estado"]] ?? null,
            "dificultadCargada" => PreguntaModel::DIFICULTADES[$p["dificultad"]],
            "dificultadHoy" => PreguntaModel::DIFICULTADES[$p["dificultad_hoy"]],
            "claseDificultadHoy" => $p["dificultad_hoy"],
            "faltanRespuestas" => $this->faltanRespuestas((int)$p["veces_mostrada"]),
            "minimoRespuestas" => PreguntaEstadisticaModel::MINIMO_RESPUESTAS,
            "historia" => $this->historiaDeLaPregunta($p),
            "respuestas" => $this->respuestasConPorcentaje($detalle["respuestas"], $t),
            "tarjetas" => [
                ["valor" => $t["respondidas"], "nombre" => "Veces respondida", "detalle" => "por " . $t["jugadores"] . " jugador" . ($t["jugadores"] === 1 ? "" : "es")],
                ["valor" => $t["porcentaje"] . "%", "nombre" => "Bien respondida", "detalle" => $t["acertadas"] . " de " . $t["respondidas"] . " veces"],
                ["valor" => $t["respondidas"] - $t["acertadas"], "nombre" => "Mal respondida", "detalle" => "incluye cuando se acabó el tiempo"],
                ["valor" => $t["sin_tiempo"], "nombre" => "Sin responder", "detalle" => "se acabó el tiempo"],
            ],
            "hayRespuestas" => $t["respondidas"] > 0,
            "reportes" => array_map([$this, "formatearReporte"], $detalle["reportes"]),
            "hayReportes" => count($detalle["reportes"]) > 0,
            "graficosJson" => json_encode($graficos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
            "usaAdminPregunta" => true,
        ], "pregunta");
    }

    // "Dar de baja" o "Activar" desde la página de una pregunta.
    // Activar una reportada es lo mismo que "Es válida" del editor (cierra sus reportes);
    // activar una sugerida es aprobarla con la dificultad con la que vino.
    public function estado()
    {
        $id = (int)Request::post("id", 0);
        if (Request::esPost()) {
            $adminId = Sesion::usuario()["id"];
            $accion = Request::post("accion");

            if ($accion === "baja" && $this->preguntaModel->darDeBaja($id, $adminId, $this->reporteModel)) {
                Sesion::flash("admin", "Pregunta #$id dada de baja. Ya no sale en el juego.");
            } elseif ($accion === "activar" && $this->preguntaModel->activar($id, $adminId, $this->reporteModel)) {
                Sesion::flash("admin", "Pregunta #$id activada. Ya sale en el juego.");
            }
        }

        Redirect::to("/admin/pregunta?id=$id");
    }

    // "Editar pregunta": el mismo formulario del editor, para cualquier pregunta.
    // Guardar no le cambia el estado: para eso están "Dar de baja" y "Activar".
    public function editar()
    {
        $datos = $this->preguntaModel->paraEditar((int)Request::get("id", 0), true);
        if ($datos === null) {
            Redirect::to("/admin/preguntas");
        }

        $this->mostrarFormulario($datos, []);
    }

    public function guardar()
    {
        $id = (int)Request::post("id", 0);
        if (!Request::esPost() || $this->preguntaModel->paraEditar($id, true) === null) {
            Redirect::to("/admin/preguntas");
        }

        $datos = $this->preguntaModel->datosDelFormulario($_POST);
        $errores = $this->preguntaModel->validar($datos, $id);
        if ($errores) {
            $this->mostrarFormulario($datos + ["id" => $id], $errores);
            return;
        }

        $this->preguntaModel->actualizar($id, $datos, Sesion::usuario()["id"]);
        Sesion::flash("admin", "Cambios guardados.");
        Redirect::to("/admin/pregunta?id=$id");
    }

    // "Nueva pregunta": el mismo formulario. La del administrador entra activa al toque
    // (la aprueba él mismo) y no tiene el límite de 15 por semana de los moderadores.
    // ?modo=shine|die: el modo de la pestaña desde donde se tocó "Nueva pregunta".
    // El formulario muestra ese modo y solo sus categorías.
    public function nueva()
    {
        $modo = PreguntaEstadisticaModel::modoValido(Request::get("modo", "shine"));
        $this->mostrarFormulario($this->preguntaModel->datosDelFormulario([]) + ["id" => null, "modo" => $modo], []);
    }

    public function crear()
    {
        if (!Request::esPost()) {
            Redirect::to("/admin/nueva");
        }

        $modo = PreguntaEstadisticaModel::modoValido(Request::post("modo", "shine"));
        $datos = $this->preguntaModel->datosDelFormulario($_POST);
        $errores = $this->preguntaModel->validar($datos);
        // La categoría tiene que ser del modo elegido (aunque alguien mande el formulario a mano).
        $idsDelModo = array_column($this->preguntaModel->categoriasPorModo()[$modo], "id");
        if ($datos["categoria_id"] !== 0 && !in_array($datos["categoria_id"], $idsDelModo, true)) {
            $errores[] = "Elegí una categoría de " . PreguntaEstadisticaModel::MODOS[$modo] . ".";
        }
        if ($errores) {
            $this->mostrarFormulario($datos + ["id" => null, "modo" => $modo], $errores);
            return;
        }

        $id = $this->preguntaModel->crear($datos, Sesion::usuario()["id"]);
        Sesion::flash("admin", "Pregunta #$id creada. Ya está activa en el juego.");
        Redirect::to("/admin/pregunta?id=$id");
    }

    // ---------- Carga masiva por CSV ----------
    // 1. /admin/csv: explica el formato y se elige el archivo.
    // 2. POST /admin/vistaPrevia: revisa cada fila y muestra cuáles están bien y cuáles no (no guarda nada).
    // 3. POST /admin/cargarCsv: guarda las válidas y muestra el resultado, con las que no se cargaron
    //    y por qué (se pueden bajar en un CSV para corregirlas y volver a subirlas).
    // Entre un paso y otro las filas quedan en la sesión, así se puede recargar la página sin perderlas.

    public function csv()
    {
        $resultado = Sesion::tomarCargaCsv("resultado");
        $vistaPrevia = Sesion::cargaCsv("vista_previa");

        if ($resultado !== null) {
            $datos = [
                "enResultado" => true,
                "cantidadCargadas" => count($resultado["cargadas"]),
                "unaCargada" => count($resultado["cargadas"]) === 1,
                "cargadas" => $resultado["cargadas"],
                "noCargadas" => $resultado["noCargadas"],
                "cantidadNoCargadas" => count($resultado["noCargadas"]),
                "hayNoCargadas" => count($resultado["noCargadas"]) > 0,
            ];
        } elseif ($vistaPrevia !== null) {
            $validas = array_filter($vistaPrevia["filas"], fn($f) => !$f["errores"]);
            $datos = [
                "enVistaPrevia" => true,
                "archivo" => $vistaPrevia["archivo"],
                "filas" => array_map(fn($f) => $f + ["ok" => !$f["errores"]], $vistaPrevia["filas"]),
                "total" => count($vistaPrevia["filas"]),
                "cantidadValidas" => count($validas),
                "unaValida" => count($validas) === 1,
                "hayValidas" => count($validas) > 0,
                "cantidadErrores" => count($vistaPrevia["filas"]) - count($validas),
            ];
        } else {
            $datos = ["enSubir" => true];
        }

        $this->mostrar("preguntas", $datos + [
            "columnas" => implode(";", CargaCsvModel::COLUMNAS),
            "maximoFilas" => CargaCsvModel::MAXIMO_FILAS,
            "errorCsv" => Sesion::tomarFlash("admin_csv"),
        ], "csv");
    }

    public function vistaPrevia()
    {
        $archivo = $_FILES["archivo"] ?? null;
        if (!Request::esPost() || $archivo === null || $archivo["error"] === UPLOAD_ERR_NO_FILE) {
            Sesion::flash("admin_csv", "Elegí un archivo .csv.");
            Redirect::to("/admin/csv");
        }
        if ($archivo["error"] !== UPLOAD_ERR_OK) {
            Sesion::flash("admin_csv", "No se pudo subir el archivo. Probá de nuevo (máximo 1 MB).");
            Redirect::to("/admin/csv");
        }

        $analisis = $this->cargaCsvModel->analizar($archivo["tmp_name"], $archivo["size"]);
        if ($analisis["error"] !== null) {
            Sesion::flash("admin_csv", $analisis["error"]);
            Redirect::to("/admin/csv");
        }

        Sesion::guardarCargaCsv("vista_previa", ["archivo" => $archivo["name"], "filas" => $analisis["filas"]]);
        Redirect::to("/admin/csv");
    }

    public function cargarCsv()
    {
        $vistaPrevia = Sesion::tomarCargaCsv("vista_previa");
        if (!Request::esPost() || $vistaPrevia === null) {
            Redirect::to("/admin/csv");
        }

        $resultado = $this->cargaCsvModel->cargar($vistaPrevia["filas"], Sesion::usuario()["id"]);
        Sesion::guardarCargaCsv("resultado", $resultado);
        Sesion::guardarCargaCsv("no_cargadas", $resultado["noCargadas"]);
        Redirect::to("/admin/csv");
    }

    // "Elegir otro archivo": descarta la vista previa.
    public function descartarCsv()
    {
        Sesion::tomarCargaCsv("vista_previa");
        Redirect::to("/admin/csv");
    }

    // "Bajar plantilla": un CSV de ejemplo con el encabezado y una pregunta de cada modo.
    public function plantilla()
    {
        $this->descargarCsv($this->cargaCsvModel->plantilla(), "plantilla-preguntas.csv");
    }

    // Las que no se cargaron, con una columna "error" al final.
    public function csvErrores()
    {
        $this->descargarCsv($this->cargaCsvModel->csvDeErrores(Sesion::cargaCsv("no_cargadas") ?? []), "preguntas-no-cargadas.csv");
    }

    private function descargarCsv($contenido, $nombre)
    {
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$nombre\"");
        echo $contenido;
    }

    // AJAX del selector Shine / Die:
    //   GET /admin/preguntasDatos?modo=die
    //   ->  {"modo": "die", "tarjetas": "<html>", "tablas": "<html>", "graficos": {...}}
    public function preguntasDatos()
    {
        $resumen = $this->preguntaEstadisticaModel->resumen(Request::get("modo", "shine"));
        $datos = $this->datosDePreguntas($resumen);

        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "modo" => $resumen["modo"],
            "tarjetas" => $this->render->generar("adminPreguntasTarjetas", $datos),
            "tablas" => $this->render->generar("adminPreguntasTablas", $datos),
            "graficos" => $this->graficosDePreguntas($resumen),
        ], JSON_UNESCAPED_UNICODE);
    }

    // Pestaña Solicitudes: las pendientes (la más vieja primero) y las últimas resueltas.
    public function solicitudes()
    {
        $pendientes = array_map([$this, "formatearPendiente"], $this->solicitudModel->pendientes());
        $revisadas = array_map([$this, "formatearRevisada"], $this->solicitudModel->revisadas());

        $this->mostrar("solicitudes", [
            "solicitudes" => $pendientes,
            "haySolicitudes" => count($pendientes) > 0,
            "revisadas" => $revisadas,
            "hayRevisadas" => count($revisadas) > 0,
            "servidor" => $this->estadoDelServidor(),
        ]);
    }

    // "Aprobar" o "Rechazar" desde la tarjeta de una solicitud.
    public function resolver()
    {
        if (Request::esPost()) {
            $accion = Request::post("accion");
            $solicitudId = (int)Request::post("solicitud_id", 0);
            $usuario = (string)Request::post("usuario", "");
            $adminId = Sesion::usuario()["id"];

            if ($accion === "aprobar") {
                Sesion::flash("admin", $this->solicitudModel->aprobar($solicitudId, $adminId)
                    ? "Listo: $usuario ya es moderador. Va a ver \"Moderar\" en el menú la próxima vez que entre al lobby."
                    : "Esa solicitud ya la había resuelto otro administrador.");
            } elseif ($accion === "rechazar") {
                Sesion::flash("admin", $this->solicitudModel->rechazar($solicitudId, $adminId)
                    ? "Solicitud de $usuario rechazada. Puede volver a pedirlo desde el lunes."
                    : "Esa solicitud ya la había resuelto otro administrador.");
            }
        }

        Redirect::to("/admin/solicitudes");
    }

    // $seccion: qué parte de la vista se muestra, si no es la de la pestaña
    // (por ejemplo, la página de una pregunta está dentro de la pestaña Preguntas).
    private function mostrar($pestaniaActiva, array $datos, $seccion = null)
    {
        $cantidadSolicitudes = count($this->solicitudModel->pendientes());

        $pestanias = [];
        foreach (self::PESTANIAS as $clave => $pestania) {
            $pestanias[] = $pestania + [
                "activa" => $clave === $pestaniaActiva,
                "cantidad" => $clave === "solicitudes" && $cantidadSolicitudes > 0 ? $cantidadSolicitudes : null,
            ];
        }

        $data = $datos + [
            // Misma estética neutra que el panel del editor.
            "tema" => "editor",
            "palabraFase" => "Shine",
            "sesion" => Sesion::usuario(),
            "pestanias" => $pestanias,
            "en" . ucfirst($seccion ?? $pestaniaActiva) => true,
            "mensaje" => Sesion::tomarFlash("admin"),
            // Para los textos que explican desde cuántas respuestas se calcula la dificultad.
            "minimoParaCalcular" => PartidaModel::MINIMO_PARA_CALCULAR,
        ];

        $this->render->renderiza("admin", $data);
    }

    // Lo que muestra el partial adminEstadisticas (tarjetas y tabla de aciertos).
    private function datosDeEstadisticas(array $resumen)
    {
        $totales = $resumen["totales"];
        $periodo = $resumen["periodo"];
        $desdeSiempre = $periodo === "todo";
        $usuarios = $resumen["aciertosPorUsuario"];

        return [
            "descripcionPeriodo" => $this->descripcionDelPeriodo($periodo),
            "tarjetas" => [
                ["valor" => $desdeSiempre ? $totales["registrados"] : $totales["jugadores"], "nombre" => "Jugadores",
                 "detalle" => $desdeSiempre ? "registrados" : "jugaron en el período · " . $totales["registrados"] . " registrados"],
                ["valor" => $totales["partidas"], "nombre" => "Partidas jugadas",
                 "detalle" => $totales["partidasShine"] . " Shine · " . $totales["partidasDie"] . " Die"],
                ["valor" => $totales["preguntasActivas"], "nombre" => "Preguntas en el juego",
                 "detalle" => "activas ahora"],
                ["valor" => $totales["preguntasCreadas"], "nombre" => "Preguntas creadas",
                 "detalle" => "por editores y jugadores · " . $totales["preguntasEnRevision"] . " sin revisar"],
                ["valor" => $totales["usuariosNuevos"], "nombre" => "Usuarios nuevos",
                 "detalle" => "se registraron en el período"],
            ],
            "aciertos" => $usuarios,
            "hayAciertos" => count($usuarios) > 0,
            "maxUsuarios" => EstadisticaModel::USUARIOS_EN_TABLA,
            "muchosUsuarios" => count($usuarios) === EstadisticaModel::USUARIOS_EN_TABLA,
        ];
    }

    // $datos["id"]: null para "Nueva pregunta", o el id de la que se edita.
    // Nueva: el modo viene en $datos["modo"] y solo se ofrecen sus categorías.
    // Editar: el modo es el de su categoría (y se puede pasar a una categoría del otro modo).
    private function mostrarFormulario(array $datos, array $errores)
    {
        $id = $datos["id"];
        $categorias = $this->preguntaModel->categoriasPorModo();
        if ($id === null) {
            $modo = $datos["modo"];
            $categorias = [$modo => $categorias[$modo]];
        } else {
            $modo = in_array($datos["categoria_id"], array_column($categorias["die"], "id"), true) ? "die" : "shine";
        }
        $otroModo = $modo === "shine" ? "die" : "shine";

        $this->mostrar("preguntas", EditorController::camposDelFormulario($datos, $categorias) + [
            "id" => $id,
            "modo" => $modo,
            "nombreModo" => PreguntaEstadisticaModel::MODOS[$modo],
            "esNueva" => $id === null,
            "otroModoUrl" => "/admin/nueva?modo=$otroModo",
            "otroModoNombre" => PreguntaEstadisticaModel::MODOS[$otroModo],
            "tituloFormulario" => $id === null ? "Nueva pregunta" : "Editar pregunta #$id",
            "accionFormulario" => $id === null ? "/admin/crear" : "/admin/guardar",
            "botonFormulario" => $id === null ? "Crear pregunta" : "Guardar cambios",
            "volverA" => $id === null ? "/admin/preguntas?modo=$modo" : "/admin/pregunta?id=$id",
            "textoVolver" => $id === null ? "Volver a Preguntas" : "Volver a la pregunta",
            "errores" => $errores,
            "dificultades" => EditorController::dificultadesParaLaVista($datos["dificultad"]),
        ], "formulario");
    }

    // Una de "Últimas preguntas agregadas": si la creó un moderador (o un administrador) sale su
    // nombre; si la sugirió un jugador, salen los dos: quién la sugirió y quién la aprobó.
    private function formatearAgregada(array $p)
    {
        $roles = ["editor" => "moderador", "admin" => "administrador", "jugador" => "jugador"];
        $sugerida = $p["aprobada_por"] !== null && (int)$p["aprobada_por"] !== (int)$p["creada_por"];
        $fecha = $sugerida ? $p["fecha_aprobacion"] : ($p["fecha_aprobacion"] ?? $p["fecha_alta"]);

        return [
            "id" => (int)$p["id"],
            "texto" => $p["texto"],
            "categoria" => $p["juego"] ? $p["categoria"] . " (" . $p["juego"] . ")" : $p["categoria"],
            "sugerida" => $sugerida,
            "creador" => $p["creador"],
            "rolCreador" => $roles[$p["rol_creador"]] ?? $p["rol_creador"],
            "aprobador" => $p["aprobador"],
            "rolAprobador" => $roles[$p["rol_aprobador"]] ?? $p["rol_aprobador"],
            "fecha" => date("d/m/Y H:i", strtotime($fecha)),
            "fechaSugerida" => date("d/m/Y", strtotime($p["fecha_alta"])),
            "dadaDeBaja" => $p["estado"] === "inactiva",
            "reportada" => $p["estado"] === "cuarentena",
        ];
    }

    // Quién la creó, la aprobó, la modificó o la dio de baja, y cuándo.
    private function historiaDeLaPregunta(array $p)
    {
        $fecha = fn($f) => date("d/m/Y", strtotime($f));
        $historia = [];
        $creador = (int)$p["creada_por"] === 1 ? "el juego (preguntas iniciales)" : $p["creada_por_usuario"];
        // Sugerida: la mandó un jugador y la aprobó otro (o todavía nadie).
        $aprobadaPorOtro = $p["aprobada_por"] !== null && (int)$p["aprobada_por"] !== (int)$p["creada_por"];
        $sugerida = $aprobadaPorOtro || $p["estado"] === "revision";
        $historia[] = ($sugerida ? "Sugerida por " : "Creada por ") . $creador . " el " . $fecha($p["fecha_alta"]);
        if ($aprobadaPorOtro) {
            $historia[] = "Aprobada por " . $p["aprobada_por_usuario"] . " el " . $fecha($p["fecha_aprobacion"]);
        }
        if ($p["modificada_por"] !== null) {
            $historia[] = "Modificada por " . $p["modificada_por_usuario"] . " el " . $fecha($p["fecha_modificacion"]);
        }
        if ($p["estado"] === "inactiva" && $p["desactivada_por"] !== null) {
            $historia[] = "Dada de baja por " . $p["desactivada_por_usuario"] . " el " . $fecha($p["fecha_desactivacion"]);
        }
        return $historia;
    }

    // Cada respuesta con su letra y qué porcentaje de las veces la eligieron.
    private function respuestasConPorcentaje(array $respuestas, array $totales)
    {
        foreach ($respuestas as $i => &$respuesta) {
            $respuesta["letra"] = PreguntaModel::LETRAS[$i];
            $respuesta["porcentaje"] = $totales["respondidas"] > 0 ? (int)round($respuesta["elegida"] * 100 / $totales["respondidas"]) : 0;
        }
        unset($respuesta);
        return $respuestas;
    }

    private function formatearReporte(array $reporte)
    {
        $estados = ["pendiente" => "Pendiente", "aprobado" => "Tenía razón", "rechazado" => "Era válida"];
        return [
            "motivo" => $this->reporteModel->nombreDelMotivo($reporte["motivo"]),
            "usuario" => $reporte["reportado_por"],
            "fecha" => date("d/m/Y", strtotime($reporte["fecha"])),
            "estado" => $estados[$reporte["estado"]],
            "pendiente" => $reporte["estado"] === "pendiente",
            "revisadoPor" => $reporte["revisado_por"],
        ];
    }

    // Lo que muestran los partials adminPreguntasTarjetas y adminPreguntasTablas.
    private function datosDePreguntas(array $resumen)
    {
        $t = $resumen["totales"];
        $minimo = PreguntaEstadisticaModel::MINIMO_RESPUESTAS;

        return [
            "nombreModo" => PreguntaEstadisticaModel::MODOS[$resumen["modo"]],
            "tarjetas" => [
                ["valor" => $t["activas"], "nombre" => "Activas", "detalle" => "salen en el juego · " . $t["total"] . " en total"],
                ["valor" => $t["reportadas"], "nombre" => "Reportadas", "detalle" => "en cuarentena, esperan al moderador"],
                ["valor" => $t["sugeridas"], "nombre" => "Sugeridas", "detalle" => "esperan revisión"],
                ["valor" => $t["inactivas"], "nombre" => "Dadas de baja", "detalle" => "ya no salen en el juego"],
                ["valor" => $t["respondidas"], "nombre" => "Veces respondidas", "detalle" => $t["porcentaje"] . "% bien respondidas"],
            ],
            "tablas" => [
                ["titulo" => "Las más falladas", "falladas" => true, "preguntas" => $resumen["masFalladas"], "hay" => count($resumen["masFalladas"]) > 0],
                ["titulo" => "Las más acertadas", "preguntas" => $resumen["masAcertadas"], "hay" => count($resumen["masAcertadas"]) > 0],
            ],
            "minimoRespuestas" => $minimo,
            "conDatos" => $resumen["dificultades"]["conDatos"],
            "ultimas" => array_map([$this, "formatearAgregada"], $resumen["ultimas"]),
            "hayUltimas" => count($resumen["ultimas"]) > 0,
        ];
    }

    private function graficosDePreguntas(array $resumen)
    {
        return [
            "categorias" => $resumen["porCategoria"],
            "dificultades" => $resumen["dificultades"],
            "notaDificultad" => $this->notaDificultad($resumen),
        ];
    }

    // "falta 1 respuesta" / "faltan 3 respuestas"; null si ya tiene la dificultad calculada.
    private function faltanRespuestas($vecesMostrada)
    {
        $faltan = PreguntaEstadisticaModel::MINIMO_RESPUESTAS - $vecesMostrada;
        if ($faltan <= 0) {
            return null;
        }
        return $faltan === 1 ? "falta 1 respuesta" : "faltan $faltan respuestas";
    }

    // Debajo del gráfico de dificultad: cuántas ya tienen la calculada. Mientras sean pocas,
    // las dos barras se ven casi iguales porque las demás usan la cargada.
    private function notaDificultad(array $resumen)
    {
        $activas = $resumen["totales"]["activas"];
        $calculadas = $resumen["dificultades"]["conDatos"];
        return "$calculadas de $activas activas ya tienen la dificultad calculada (se calcula desde "
            . PartidaModel::MINIMO_PARA_CALCULAR . " respuestas). Las demás usan la cargada.";
    }

    private function modos($elegido)
    {
        $modos = [];
        foreach (PreguntaEstadisticaModel::MODOS as $valor => $nombre) {
            $modos[] = [
                "valor" => $valor,
                "nombre" => $nombre,
                "url" => "/admin/preguntas?modo=$valor",
                "activo" => $valor === $elegido,
            ];
        }
        return $modos;
    }

    // Solo lo que necesita Chart.js: etiquetas y valores de cada gráfico.
    private function graficos(array $resumen)
    {
        return [
            "tiempo" => $resumen["partidasEnElTiempo"] + [
                "titulo" => self::TIEMPO[$resumen["periodo"]]["titulo"],
                "ejeX" => self::TIEMPO[$resumen["periodo"]]["ejeX"],
            ],
            "pais" => $resumen["porPais"],
            "sexo" => $resumen["porSexo"],
            "edad" => $resumen["porEdad"],
        ];
    }

    // Un gráfico del PDF con su tabla de datos al lado (la consigna pide poder imprimir las tablas).
    private function demografia($titulo, $columna, array $serie, $imagen)
    {
        $filas = [];
        foreach ($serie["etiquetas"] as $i => $etiqueta) {
            $valor = $serie["valores"][$i];
            $filas[] = [
                "etiqueta" => $etiqueta,
                "valor" => $valor,
                "porcentaje" => $serie["total"] > 0 ? round($valor * 100 / $serie["total"]) : 0,
            ];
        }

        return [
            "titulo" => $titulo,
            "columna" => $columna,
            "imagen" => $imagen,
            "anchoImagen" => 360,
            "filas" => $filas,
            "total" => $serie["total"],
        ];
    }

    private function periodos($elegido)
    {
        $periodos = [];
        foreach (EstadisticaModel::PERIODOS as $valor => $nombre) {
            $periodos[] = [
                "valor" => $valor,
                "nombre" => $nombre,
                "url" => "/admin?periodo=$valor",
                "activo" => $valor === $elegido,
            ];
        }
        return $periodos;
    }

    private function descripcionDelPeriodo($periodo)
    {
        $hoy = new \DateTimeImmutable("today");
        $meses = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto",
                  "septiembre", "octubre", "noviembre", "diciembre"];

        switch ($periodo) {
            case "dia":
                return "Hoy, " . $hoy->format("d/m/Y");
            case "semana":
                return "Semana del " . $hoy->modify("monday this week")->format("d/m")
                    . " al " . $hoy->modify("sunday this week")->format("d/m/Y");
            case "mes":
                return ucfirst($meses[(int)$hoy->format("n") - 1]) . " de " . $hoy->format("Y");
            case "anio":
                return "Año " . $hoy->format("Y");
            default:
                return "Desde siempre";
        }
    }

    private function formatearPendiente(array $solicitud)
    {
        $solicitud["fecha"] = date("d/m H:i", strtotime($solicitud["fecha"]));
        $solicitud["foto"] = $solicitud["foto"] ?: "/public/img/avatar.svg";
        $solicitud["usuarioUrl"] = "/perfil/ver?usuario=" . rawurlencode($solicitud["usuario"]);
        $solicitud["semanas"] = $this->solicitudModel->semanasCumplidas($solicitud["usuario_id"]);
        $solicitud["porcentaje"] = (int)$solicitud["preguntas_respondidas"] > 0
            ? round((int)$solicitud["preguntas_acertadas"] * 100 / (int)$solicitud["preguntas_respondidas"])
            : 0;
        return $solicitud;
    }

    private function formatearRevisada(array $solicitud)
    {
        $solicitud["fecha"] = date("d/m/Y", strtotime($solicitud["fecha_revision"]));
        $solicitud["aprobada"] = $solicitud["estado"] === "aprobada";
        return $solicitud;
    }

    // Qué extensiones de PHP tiene el servidor. Sirve para saber, antes de armar el PDF,
    // si InfinityFree tiene todo lo que necesitan Dompdf y CpChart.
    private function estadoDelServidor()
    {
        $extensiones = [];
        foreach (self::EXTENSIONES as $nombre => $paraQue) {
            $extensiones[] = ["nombre" => $paraQue, "disponible" => extension_loaded($nombre)];
        }

        return [
            "php" => PHP_VERSION,
            "extensiones" => $extensiones,
            "todoDisponible" => count(array_filter($extensiones, fn($e) => !$e["disponible"])) === 0,
        ];
    }
}
