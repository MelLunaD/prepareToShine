<?php

namespace app\controller;

use app\model\CementerioModel;
use app\model\RankingModel;
use Qr;
use Request;
use Sesion;

// Ranking de un modo: /ranking?modo=shine|die&periodo=semana|historico&tipo=puntos|ganadas|racha
// (Die suma periodo=cementerio, que no tiene tipos) con filtros opcionales &pais=AR&edad=18-39. Shine y Die son rankings separados: se entra desde
// el botón Ranking del lobby de cada modo y ninguno muestra nada del otro.
class RankingController
{
    const PERIODOS = ["semana" => "Semanal", "historico" => "Histórico"];
    // El cementerio es un ranking más, solo de Die. No tiene tipos: siempre cuenta muertes.
    const CEMENTERIO = ["cementerio" => "Cementerio"];
    // La racha es solo histórica.
    const TIPOS = [
        "semana" => ["puntos" => "Puntos", "ganadas" => "Ganadas"],
        "historico" => ["puntos" => "Puntos", "ganadas" => "Ganadas", "racha" => "Racha"],
    ];

    private $rankingModel;
    private $cementerioModel;
    private $render;

    public function __construct($rankingModel, $cementerioModel, $render)
    {
        $this->rankingModel = $rankingModel;
        $this->cementerioModel = $cementerioModel;
        $this->render = $render;
    }

    // La pantalla entera: /ranking?modo=...&periodo=...&tipo=...&pais=...&edad=...
    public function show()
    {
        $this->render->renderiza("ranking", $this->datos());
    }

    // AJAX: /ranking/lista con los mismos parámetros. Devuelve solo el pedazo de la página que
    // cambia (pestañas, filtros, lista y sus miniperfiles), armado con la misma vista Mustache.
    // public/js/ranking.js lo pide con fetch() al tocar una pestaña o un filtro y lo pone en la
    // página sin recargarla. Es HTML y no JSON para que la vista siga estando solo en Mustache.
    public function lista()
    {
        echo $this->render->generar("rankingContenido", $this->datos());
    }

    // Todo lo que muestra el ranking según los parámetros de la URL. Lo usan show() y lista().
    private function datos()
    {
        $usuario = Sesion::usuario();
        $modo = Request::get("modo") === "die" ? "die" : "shine";
        // Los links viejos (periodo=historico&tipo=cementerio) siguen llevando al cementerio.
        $periodo = Request::get("tipo") === "cementerio" ? "cementerio" : Request::get("periodo");
        $periodo = in_array($periodo, array_keys(self::periodos($modo)), true) ? $periodo : "semana";
        $esCementerio = $periodo === "cementerio";
        $tipo = $esCementerio ? "cementerio" : ($this->parametro("tipo", self::TIPOS[$periodo]) ?? "puntos");
        // Los números del jugador que se usan: el cementerio toma los históricos (cantidad de partidas, etc.).
        $periodoDatos = $esCementerio ? "historico" : $periodo;

        $jugadores = $this->rankingModel->jugadores($modo);
        if ($esCementerio) {
            $muertes = $this->cementerioModel->muertes();
            foreach ($jugadores as $id => $jugador) {
                $jugadores[$id]["muertes"] = $muertes[$id] ?? null;
            }
        }
        $rangos = $this->rankingModel->rangos($modo);

        // Filtros: solo se aceptan países que tienen jugadores y los grupos de edad definidos.
        $paises = RankingModel::paises($jugadores);
        $pais = $this->parametro("pais", $paises);
        $edad = $this->parametro("edad", RankingModel::GRUPOS_DE_EDAD);

        $filtrados = RankingModel::filtrar($jugadores, $pais, $edad);
        $lista = $esCementerio
            ? CementerioModel::ordenar($filtrados)
            : RankingModel::ordenar($filtrados, $periodoDatos, $tipo);

        // Flechas: contra el puesto de ayer. El lunes el semanal arranca de cero, así que no hay.
        // El cementerio no lleva flechas.
        $puestosDeAyer = $esCementerio || ($periodo === "semana" && date("N") === "1")
            ? null
            : RankingModel::puestosDeAyer($filtrados, $periodo, $tipo);
        $cantidad = $esCementerio ? CementerioModel::CANTIDAD_EN_CEMENTERIO : RankingModel::CANTIDAD_EN_RANKING[$periodoDatos];
        $filas = array_slice($lista, 0, $cantidad);

        // Si el jugador quedó fuera del top, igual ve su puesto debajo de la lista.
        $miFila = null;
        foreach ($lista as $jugador) {
            if ((int)$jugador["id"] === (int)$usuario["id"] && $jugador["puesto"] > $cantidad) {
                $miFila = $jugador;
            }
        }

        // Salón de la fama: el podio de puntos de la semana pasada, sin filtros.
        $podio = array_slice(RankingModel::ordenar($jugadores, "pasada", "puntos"), 0, 3);

        $filtros = ["modo" => $modo, "periodo" => $periodo, "tipo" => $esCementerio ? null : $tipo, "pais" => $pais, "edad" => $edad];
        $formatear = function ($jugador) use ($periodoDatos, $tipo, $modo, $usuario, $puestosDeAyer, $esCementerio) {
            $fila = $this->formatearFila($jugador, $periodoDatos, $tipo, $modo, $usuario["id"])
                + ["cambio" => $this->cambio($jugador, $puestosDeAyer)];
            return $esCementerio ? $this->lapida($jugador) + $fila : $fila;
        };

        $data = [
            "tema" => $modo === "die" ? "souls" : "shine",
            "palabraFase" => $modo === "die" ? "Die" : "Shine",
            "sesion" => $usuario,
            "usaRanking" => true,
            "esDie" => $modo === "die",
            "modo" => $modo,
            "periodo" => $periodo,
            "tipo" => $tipo,
            "esCementerio" => $esCementerio,
            "volver" => $modo === "die" ? "/?modo=die" : "/",
            "pestaniasPeriodo" => $this->pestanias(self::periodos($modo), $periodo, $filtros, "periodo"),
            "pestaniasTipo" => $esCementerio ? [] : $this->pestanias(self::TIPOS[$periodo], $tipo, $filtros, "tipo"),
            "hayPestaniasTipo" => !$esCementerio,
            "explicacion" => $this->explicacion($periodo, $tipo, $modo),
            "filas" => array_map($formatear, $filas),
            "miFila" => $miFila ? $formatear($miFila) : null,
            "hayFilas" => count($filas) > 0,
            "podio" => array_map(function ($jugador) use ($modo, $usuario) {
                return ["dialogo" => "miniperfil-podio-" . $jugador["id"]]
                + $this->formatearFila($jugador, "pasada", "puntos", $modo, $usuario["id"]);
            }, $podio),
            "hayPodio" => count($podio) > 0,
            "paises" => $this->opciones($paises, $pais),
            "edades" => $this->opciones(array_combine(array_keys(RankingModel::GRUPOS_DE_EDAD), array_map(function ($grupo) {
                return $grupo . " años";
            }, array_keys(RankingModel::GRUPOS_DE_EDAD))), $edad),
            "hayFiltros" => $pais !== null || $edad !== null,
            "limpiarFiltros" => $this->url(["pais" => null, "edad" => null] + $filtros),
        ];

        // Un miniperfil por jugador de la lista (y su fila) y otro por cada uno del podio. Van por
        // separado porque los de la lista se reemplazan con AJAX y los del podio quedan fijos.
        $enLista = [];
        foreach (array_merge($filas, $miFila ? [$miFila] : []) as $jugador) {
            $enLista[(int)$jugador["id"]] = $jugador;
        }
        $data["miniperfilesLista"] = array_values(array_map(function ($jugador) use ($rangos, $modo) {
            return $this->miniperfil($jugador, $rangos, $modo, "miniperfil-");
        }, $enLista));
        $data["miniperfilesPodio"] = array_map(function ($jugador) use ($rangos, $modo) {
            return $this->miniperfil($jugador, $rangos, $modo, "miniperfil-podio-");
        }, $podio);
        $enPantalla = $enLista;
        foreach ($podio as $jugador) {
            $enPantalla[(int)$jugador["id"]] = $jugador;
        }

        // El rango de cada fila sale de los puntos de esta semana, como en el lobby.
        foreach (["filas", "podio"] as $clave) {
            foreach ($data[$clave] as $i => $fila) {
                $data[$clave][$i]["rango"] = $this->rango($rangos, $enPantalla[$fila["id"]]["semana"]["puntos"]);
            }
        }
        if ($data["miFila"]) {
            $data["miFila"]["rango"] = $this->rango($rangos, $miFila["semana"]["puntos"]);
        }

        return $data;
    }

    // Pestañas grandes de un modo: Semanal e Histórico, y en Die también Cementerio.
    private static function periodos($modo)
    {
        return $modo === "die" ? self::PERIODOS + self::CEMENTERIO : self::PERIODOS;
    }

    // Lo que dice la lápida de un jugador en el cementerio.
    private function lapida(array $jugador)
    {
        $muertes = $jugador["muertes"];

        return [
            "muertes" => $muertes["muertes"],
            "textoMuertes" => $muertes["muertes"] === 1 ? "murió 1 vez" : "murió " . $muertes["muertes"] . " veces",
            "frase" => CementerioModel::frase($jugador["id"], $muertes["muertes"]),
            "causa" => $muertes["causa"],
            "fechaUltima" => date("d/m/Y", strtotime($muertes["ultimaFecha"])),
            "adorno" => CementerioModel::adorno($jugador["id"]),
        ];
    }

    // Valor de un parámetro de la URL si es una de las opciones válidas; si no, null.
    private function parametro($nombre, array $validos)
    {
        $valor = Request::get($nombre);
        return is_string($valor) && array_key_exists($valor, $validos) ? $valor : null;
    }

    private function formatearFila(array $jugador, $periodo, $tipo, $modo, $miId)
    {
        $numeros = $jugador[$periodo];
        $unidad = $modo === "die" ? "almas" : "pts";

        if ($tipo === "racha") {
            $valor = $jugador["racha"]["mejor"];
            $unidad = "seguidas";
            $detalle = null;
        } elseif ($tipo === "ganadas") {
            $valor = $numeros["ganadas"];
            $unidad = $valor === 1 ? "ganada" : "ganadas";
            $detalle = "en " . $this->partidas($numeros["partidas"]);
        } else {
            $valor = $numeros["puntos"];
            $detalle = $this->partidas($numeros["partidas"]);
        }

        return [
            "id" => (int)$jugador["id"],
            "dialogo" => "miniperfil-" . $jugador["id"],
            "puesto" => $jugador["puesto"],
            "medalla" => $jugador["puesto"] <= 3 ? $jugador["puesto"] : null,
            "usuario" => $jugador["usuario"],
            "foto" => $jugador["foto"] ?: "/public/img/avatar.svg",
            "pais_codigo" => $jugador["pais_codigo"],
            "pais_codigo_min" => strtolower($jugador["pais_codigo"]),
            "pais_nombre" => $jugador["pais_nombre"],
            "urlPerfil" => "/perfil/ver?usuario=" . urlencode($jugador["usuario"]) . ($modo === "die" ? "&modo=die" : ""),
            "valor" => $valor,
            "unidad" => $unidad,
            "detalle" => $detalle,
            "esYo" => (int)$jugador["id"] === (int)$miId,
        ];
    }

    // Flecha de la fila comparando con ayer: ▲ subió, ▼ bajó, – igual, NUEVO si ayer no estaba.
    // null cuando no hay flechas (el semanal los lunes).
    private function cambio(array $jugador, $puestosDeAyer)
    {
        if ($puestosDeAyer === null) {
            return null;
        }
        $antes = $puestosDeAyer[(int)$jugador["id"]] ?? null;
        if ($antes === null) {
            return ["clase" => "nuevo", "texto" => "Nuevo", "titulo" => "Ayer no estaba en este ranking"];
        }
        $diferencia = $antes - $jugador["puesto"];
        if ($diferencia > 0) {
            return ["clase" => "sube", "texto" => "▲" . $diferencia, "titulo" => "Subió " . $diferencia . " desde ayer"];
        }
        if ($diferencia < 0) {
            return ["clase" => "baja", "texto" => "▼" . -$diferencia, "titulo" => "Bajó " . -$diferencia . " desde ayer"];
        }
        return ["clase" => "igual", "texto" => "–", "titulo" => "Igual que ayer"];
    }

    // $prefijo arma el id del <dialog> ("miniperfil-7" o "miniperfil-podio-7").
    private function miniperfil(array $jugador, array $rangos, $modo, $prefijo)
    {
        $url = Qr::urlPerfil($jugador["usuario"], $modo);
        $rango = $this->rango($rangos, $jugador["semana"]["puntos"]);

        // Con los mismos nombres que usa el partial rangoCaja (lobby y perfil).
        return [
            "dialogo" => $prefijo . $jugador["id"],
            "rango" => $rango["nombre"] ?? null,
            "colorRango" => $rango["color"] ?? null,
            "iconoRango" => $rango["icono"] ?? null,
            "rangoBrilla" => $rango["brilla"] ?? false,
            "id" => (int)$jugador["id"],
            "usuario" => $jugador["usuario"],
            "nombre_completo" => $jugador["nombre_completo"],
            "foto" => $jugador["foto"] ?: "/public/img/avatar.svg",
            "pais_codigo" => $jugador["pais_codigo"],
            "pais_codigo_min" => strtolower($jugador["pais_codigo"]),
            "pais_nombre" => $jugador["pais_nombre"],
            "puntosSemana" => $jugador["semana"]["puntos"],
            "puntosHistorico" => $jugador["historico"]["puntos"],
            "rachaActual" => $jugador["racha"]["actual"],
            "mejorRacha" => $jugador["racha"]["mejor"],
            "urlPerfil" => $url,
            "qr" => Qr::imagen($url, 140),
        ];
    }

    private function rango(array $rangos, $puntos)
    {
        $rango = RankingModel::rangoPara($rangos, $puntos);
        if ($rango === null) {
            return null;
        }

        return [
            "nombre" => $rango["nombre"],
            "color" => $rango["color"],
            "brilla" => (bool)$rango["brilla"],
            "icono" => !empty($rango["icono"]) ? "/public/img/rangos/" . $rango["icono"] : null,
        ];
    }

    private function partidas($cantidad)
    {
        return $cantidad . ($cantidad === 1 ? " partida" : " partidas");
    }

    private function explicacion($periodo, $tipo, $modo)
    {
        $puntos = $modo === "die" ? "las almas" : "los puntos";

        if ($tipo === "cementerio") {
            return "Los que más veces murieron en Die (un error o el tiempo agotado; retirarse en una hoguera no cuenta). A igual cantidad, primero el que llegó antes.";
        }
        if ($tipo === "racha") {
            return "Respuestas correctas seguidas, contando todas las partidas. Un error la corta.";
        }
        if ($tipo === "ganadas") {
            return "Partidas con las 15 preguntas bien" . ($periodo === "semana" ? " desde el lunes." : ".")
                . " A igual cantidad, gana el que jugó menos partidas.";
        }
        if ($periodo === "semana") {
            return ucfirst($puntos) . " desde el lunes, con el enfriamiento por los días sin jugar. A igual puntaje, gana el que jugó menos partidas.";
        }
        return ($modo === "die" ? "Todas " : "Todos ") . $puntos . " de siempre, sin enfriamiento. A igual puntaje, gana el que jugó menos partidas.";
    }

    // Pestañas como links: cambian un parámetro y conservan el resto (modo y filtros).
    private function pestanias(array $opciones, $actual, array $filtros, $parametro)
    {
        $pestanias = [];
        foreach ($opciones as $valor => $texto) {
            $nuevos = [$parametro => $valor] + $filtros;
            // El cementerio no tiene tipos. Al pasar a Semanal desde Racha (la racha es solo
            // histórica) o al salir del cementerio se vuelve a Puntos.
            if ($parametro === "periodo") {
                if ($valor === "cementerio") {
                    $nuevos["tipo"] = null;
                } elseif (!array_key_exists($filtros["tipo"] ?? "", self::TIPOS[$valor])) {
                    $nuevos["tipo"] = "puntos";
                }
            }
            $pestanias[] = ["texto" => $texto, "url" => $this->url($nuevos), "activa" => $valor === $actual];
        }
        return $pestanias;
    }

    private function url(array $parametros)
    {
        // Siempre en el mismo orden, así la URL se lee igual venga de donde venga.
        $orden = ["modo" => null, "periodo" => null, "tipo" => null, "pais" => null, "edad" => null];
        $parametros = array_merge($orden, $parametros);

        return "/ranking?" . http_build_query(array_filter($parametros, function ($valor) {
            return $valor !== null;
        }));
    }

    private function opciones(array $opciones, $elegida)
    {
        $lista = [];
        foreach ($opciones as $valor => $texto) {
            $lista[] = ["valor" => $valor, "texto" => $texto, "elegida" => $valor === $elegida];
        }
        return $lista;
    }
}
