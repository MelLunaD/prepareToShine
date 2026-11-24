<?php

namespace app\controller;

use app\model\EstadisticaModel;
use Grafico;
use Pdf;
use Redirect;
use Request;
use Sesion;

// Panel del administrador. Solo entra el rol admin: lo controla el Router con
// Configuration::ROLES_POR_CONTROLLER. El administrador no juega: al entrar al sitio
// lo manda acá el HomeController.
// Pestañas: Estadísticas (números y gráficos filtrables por período) y Solicitudes ("Quiero moderar").
class AdminController
{
    const PESTANIAS = [
        "estadisticas" => ["titulo" => "Estadísticas", "url" => "/admin"],
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
    private $solicitudModel;
    private $render;

    public function __construct($estadisticaModel, $solicitudModel, $render)
    {
        $this->estadisticaModel = $estadisticaModel;
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

    private function mostrar($pestaniaActiva, array $datos)
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
            "en" . ucfirst($pestaniaActiva) => true,
            "mensaje" => Sesion::tomarFlash("admin"),
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
