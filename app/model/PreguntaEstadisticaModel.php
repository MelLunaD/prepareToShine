<?php

namespace app\model;

// Números y gráficos de las preguntas para el panel del administrador (pestaña Preguntas).
// Todo va separado por modo: Shine y Die tienen categorías distintas.
// Los aciertos salen de pregunta.veces_mostrada y pregunta.veces_acertada, los mismos
// contadores que usa el juego para calcular la dificultad (PartidaModel::DIFICULTAD_SQL).
class PreguntaEstadisticaModel
{
    const MODOS = ["shine" => "Shine", "die" => "Die"];

    // Para las tablas de "más falladas" y "más acertadas" solo cuentan las que se respondieron
    // un mínimo de veces: el mismo con el que el juego empieza a calcular la dificultad.
    const MINIMO_RESPUESTAS = PartidaModel::MINIMO_PARA_CALCULAR;
    const PREGUNTAS_EN_TABLA = 5;
    const ULTIMAS_AGREGADAS = 5;

    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    public static function modoValido($modo)
    {
        return isset(self::MODOS[$modo]) ? $modo : "shine";
    }

    // Todo lo del modo en un solo arreglo: lo usan la pantalla y el JSON del AJAX.
    public function resumen($modo)
    {
        $modo = self::modoValido($modo);

        // Con pocas preguntas respondidas, una misma podría salir en las dos listas: se deja
        // solo en la de más falladas.
        $masFalladas = $this->extremos($modo, "ASC");
        $idsFalladas = array_column($masFalladas, "id");
        $masAcertadas = array_values(array_filter($this->extremos($modo, "DESC"),
            fn($p) => !in_array($p["id"], $idsFalladas, true)));

        return [
            "modo" => $modo,
            "totales" => $this->totales($modo),
            "porCategoria" => $this->porCategoria($modo),
            "dificultades" => $this->dificultades($modo),
            "masFalladas" => $masFalladas,
            "masAcertadas" => $masAcertadas,
            "ultimas" => $this->ultimasAgregadas($modo),
        ];
    }

    // Cuántas hay en cada estado y cuántas veces se respondieron.
    private function totales($modo)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(p.estado = 'activa'), 0) AS activas,
                    COALESCE(SUM(p.estado = 'cuarentena'), 0) AS reportadas,
                    COALESCE(SUM(p.estado = 'revision'), 0) AS sugeridas,
                    COALESCE(SUM(p.estado = 'inactiva'), 0) AS inactivas,
                    COALESCE(SUM(p.veces_mostrada), 0) AS respondidas,
                    COALESCE(SUM(p.veces_acertada), 0) AS acertadas
             FROM pregunta p JOIN categoria c ON c.id = p.categoria_id
             WHERE c.fase = ?",
            [$modo]
        );

        $totales = array_map("intval", $fila);
        $totales["porcentaje"] = $this->porcentaje($totales["acertadas"], $totales["respondidas"]);
        return $totales;
    }

    // Por categoría: cuántas activas hay de cada dificultad (la calculada, la que usa el juego hoy)
    // y qué porcentaje de las respuestas fueron correctas.
    private function porCategoria($modo)
    {
        $filas = $this->database->queryAll(
            "SELECT c.nombre,
                    COALESCE(SUM(p.estado = 'activa' AND " . PartidaModel::DIFICULTAD_SQL . " = 'facil'), 0) AS facil,
                    COALESCE(SUM(p.estado = 'activa' AND " . PartidaModel::DIFICULTAD_SQL . " = 'media'), 0) AS media,
                    COALESCE(SUM(p.estado = 'activa' AND " . PartidaModel::DIFICULTAD_SQL . " = 'dificil'), 0) AS dificil,
                    COALESCE(SUM(p.veces_mostrada), 0) AS respondidas,
                    COALESCE(SUM(p.veces_acertada), 0) AS acertadas
             FROM categoria c LEFT JOIN pregunta p ON p.categoria_id = c.id
             WHERE c.fase = ?
             GROUP BY c.id, c.nombre
             ORDER BY c.id",
            [$modo]
        );

        $categorias = [
            "etiquetas" => [],
            "facil" => [], "media" => [], "dificil" => [],
            "porcentajes" => [],
            "respondidas" => [],
        ];
        foreach ($filas as $fila) {
            $categorias["etiquetas"][] = $fila["nombre"];
            $categorias["facil"][] = (int)$fila["facil"];
            $categorias["media"][] = (int)$fila["media"];
            $categorias["dificil"][] = (int)$fila["dificil"];
            $categorias["porcentajes"][] = $this->porcentaje((int)$fila["acertadas"], (int)$fila["respondidas"]);
            $categorias["respondidas"][] = (int)$fila["respondidas"];
        }
        return $categorias;
    }

    // Las activas según la dificultad con la que se cargaron y según la que calcula el juego
    // con los aciertos. Si muchas "fáciles" pasaron a "difíciles", la carga inicial estaba mal.
    private function dificultades($modo)
    {
        $fila = $this->database->queryOne(
            "SELECT COALESCE(SUM(p.dificultad = 'facil'), 0) AS inicial_facil,
                    COALESCE(SUM(p.dificultad = 'media'), 0) AS inicial_media,
                    COALESCE(SUM(p.dificultad = 'dificil'), 0) AS inicial_dificil,
                    COALESCE(SUM(" . PartidaModel::DIFICULTAD_SQL . " = 'facil'), 0) AS hoy_facil,
                    COALESCE(SUM(" . PartidaModel::DIFICULTAD_SQL . " = 'media'), 0) AS hoy_media,
                    COALESCE(SUM(" . PartidaModel::DIFICULTAD_SQL . " = 'dificil'), 0) AS hoy_dificil,
                    COALESCE(SUM(p.veces_mostrada >= " . self::MINIMO_RESPUESTAS . "), 0) AS con_datos
             FROM pregunta p JOIN categoria c ON c.id = p.categoria_id
             WHERE c.fase = ? AND p.estado = 'activa'",
            [$modo]
        );

        return [
            "etiquetas" => array_values(PreguntaModel::DIFICULTADES),
            "inicial" => [(int)$fila["inicial_facil"], (int)$fila["inicial_media"], (int)$fila["inicial_dificil"]],
            "hoy" => [(int)$fila["hoy_facil"], (int)$fila["hoy_media"], (int)$fila["hoy_dificil"]],
            "conDatos" => (int)$fila["con_datos"],
        ];
    }

    // Las últimas que entraron al juego (o que entraron y después se dieron de baja): las que creó
    // un moderador o un administrador y las sugeridas que ya se aprobaron. No entran las iniciales
    // (las cargó el juego, usuario "sistema") ni las sugeridas que todavía esperan revisión.
    // Una sugerida "entra" cuando la aprueban, por eso se ordena por fecha de aprobación.
    private function ultimasAgregadas($modo)
    {
        return $this->database->queryAll(
            "SELECT p.id, p.texto, p.juego, p.estado, c.nombre AS categoria,
                    p.creada_por, p.aprobada_por, p.fecha_alta, p.fecha_aprobacion,
                    creador.usuario AS creador, creador.rol AS rol_creador,
                    aprobador.usuario AS aprobador, aprobador.rol AS rol_aprobador
             FROM pregunta p
             JOIN categoria c ON c.id = p.categoria_id
             JOIN usuario creador ON creador.id = p.creada_por
             LEFT JOIN usuario aprobador ON aprobador.id = p.aprobada_por
             WHERE c.fase = ? AND p.creada_por <> 1 AND p.estado <> 'revision'
             ORDER BY COALESCE(p.fecha_aprobacion, p.fecha_alta) DESC, p.id DESC
             LIMIT " . self::ULTIMAS_AGREGADAS,
            [$modo]
        );
    }

    // Las 5 activas con menos (ASC) o más (DESC) porcentaje de aciertos.
    private function extremos($modo, $orden)
    {
        $filas = $this->database->queryAll(
            "SELECT p.id, p.texto, p.juego, c.nombre AS categoria, p.veces_mostrada, p.veces_acertada,
                    ROUND(p.veces_acertada * 100 / p.veces_mostrada) AS porcentaje
             FROM pregunta p JOIN categoria c ON c.id = p.categoria_id
             WHERE c.fase = ? AND p.estado = 'activa' AND p.veces_mostrada >= " . self::MINIMO_RESPUESTAS . "
             ORDER BY porcentaje $orden, p.veces_mostrada DESC, p.id
             LIMIT " . self::PREGUNTAS_EN_TABLA,
            [$modo]
        );

        return array_map(fn($f) => [
            "id" => (int)$f["id"],
            "texto" => $f["texto"],
            "categoria" => $f["juego"] ? $f["categoria"] . " (" . $f["juego"] . ")" : $f["categoria"],
            "respondidas" => (int)$f["veces_mostrada"],
            "acertadas" => (int)$f["veces_acertada"],
            "porcentaje" => (int)$f["porcentaje"],
        ], $filas);
    }

    // ---------- Una sola pregunta (página /admin/pregunta?id=) ----------

    // Todo sobre una pregunta: sus datos, quién la tocó, sus respuestas y cómo le fue en el juego.
    // Devuelve null si no existe.
    // Los números salen de partida_pregunta (cada vez que le tocó a alguien), así se pueden
    // cruzar con el nivel del jugador, su edad o la fecha.
    public function detalle($id)
    {
        $pregunta = $this->database->queryOne(
            "SELECT p.*, " . PartidaModel::DIFICULTAD_SQL . " AS dificultad_hoy,
                    c.nombre AS categoria, c.fase,
                    creador.usuario AS creada_por_usuario, creador.rol AS creada_por_rol,
                    aprobador.usuario AS aprobada_por_usuario,
                    modificador.usuario AS modificada_por_usuario,
                    desactivador.usuario AS desactivada_por_usuario
             FROM pregunta p
             JOIN categoria c ON c.id = p.categoria_id
             JOIN usuario creador ON creador.id = p.creada_por
             LEFT JOIN usuario aprobador ON aprobador.id = p.aprobada_por
             LEFT JOIN usuario modificador ON modificador.id = p.modificada_por
             LEFT JOIN usuario desactivador ON desactivador.id = p.desactivada_por
             WHERE p.id = ?",
            [$id]
        );
        if ($pregunta === null) {
            return null;
        }

        return [
            "pregunta" => $pregunta,
            "respuestas" => $this->respuestasElegidas($id),
            "totales" => $this->totalesDeLaPregunta($id),
            "porNivel" => $this->aciertosPorNivel($id),
            "porEdad" => $this->aciertosPorEdad($id),
            "enElTiempo" => $this->respuestasPorMes($id),
            "reportes" => $this->reportesDeLaPregunta($id),
        ];
    }

    // Cada respuesta con cuántas veces la eligieron. Sirve para ver qué opción incorrecta "engaña".
    private function respuestasElegidas($id)
    {
        $filas = $this->database->queryAll(
            "SELECT r.id, r.texto, r.es_correcta, COUNT(pp.id) AS elegida
             FROM respuesta r LEFT JOIN partida_pregunta pp ON pp.respuesta_id = r.id
             WHERE r.pregunta_id = ?
             GROUP BY r.id, r.texto, r.es_correcta
             ORDER BY r.id",
            [$id]
        );

        return array_map(fn($f) => [
            "texto" => $f["texto"],
            "correcta" => (int)$f["es_correcta"] === 1,
            "elegida" => (int)$f["elegida"],
        ], $filas);
    }

    // acerto = NULL: la pregunta está en pantalla ahora (partida en curso), todavía no cuenta.
    // respuesta_id = NULL con acerto = 0: se le acabó el tiempo.
    private function totalesDeLaPregunta($id)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS respondidas,
                    COALESCE(SUM(pp.acerto = 1), 0) AS acertadas,
                    COALESCE(SUM(pp.respuesta_id IS NULL), 0) AS sin_tiempo,
                    COUNT(DISTINCT pa.usuario_id) AS jugadores
             FROM partida_pregunta pp JOIN partida pa ON pa.id = pp.partida_id
             WHERE pp.pregunta_id = ? AND pp.acerto IS NOT NULL",
            [$id]
        );

        $totales = array_map("intval", $fila);
        $totales["porcentaje"] = $this->porcentaje($totales["acertadas"], $totales["respondidas"]);
        return $totales;
    }

    // Por nivel del jugador (0 a 10) al empezar la partida: bien y mal en cada uno.
    private function aciertosPorNivel($id)
    {
        $filas = $this->database->queryAll(
            "SELECT pa.nivel, COUNT(*) AS respondidas, COALESCE(SUM(pp.acerto = 1), 0) AS acertadas
             FROM partida_pregunta pp JOIN partida pa ON pa.id = pp.partida_id
             WHERE pp.pregunta_id = ? AND pp.acerto IS NOT NULL
             GROUP BY pa.nivel",
            [$id]
        );

        $porNivel = array_fill(0, 11, ["respondidas" => 0, "acertadas" => 0]);
        foreach ($filas as $fila) {
            $porNivel[(int)$fila["nivel"]] = ["respondidas" => (int)$fila["respondidas"], "acertadas" => (int)$fila["acertadas"]];
        }
        return $this->bienYMal(array_map(fn($n) => "N" . $n, array_keys($porNivel)), $porNivel);
    }

    // Por grupo de edad del jugador (los mismos grupos del ranking y de Estadísticas).
    private function aciertosPorEdad($id)
    {
        $filas = $this->database->queryAll(
            "SELECT YEAR(CURDATE()) - u.anio_nacimiento AS edad, COUNT(*) AS respondidas,
                    COALESCE(SUM(pp.acerto = 1), 0) AS acertadas
             FROM partida_pregunta pp
             JOIN partida pa ON pa.id = pp.partida_id
             JOIN usuario u ON u.id = pa.usuario_id
             WHERE pp.pregunta_id = ? AND pp.acerto IS NOT NULL
             GROUP BY edad",
            [$id]
        );

        $grupos = EstadisticaModel::GRUPOS_DE_EDAD;
        $porGrupo = array_fill_keys(array_keys($grupos), ["respondidas" => 0, "acertadas" => 0]);
        foreach ($filas as $fila) {
            $grupo = array_key_first($grupos); // menos de 12 cuenta con los menores
            foreach ($grupos as $nombre => [$desde, $hasta]) {
                if ((int)$fila["edad"] >= $desde && (int)$fila["edad"] <= $hasta) {
                    $grupo = $nombre;
                }
            }
            $porGrupo[$grupo]["respondidas"] += (int)$fila["respondidas"];
            $porGrupo[$grupo]["acertadas"] += (int)$fila["acertadas"];
        }
        return $this->bienYMal(array_keys($porGrupo), array_values($porGrupo));
    }

    // Respuestas de los últimos 12 meses, mes por mes (los meses sin respuestas van con 0).
    private function respuestasPorMes($id)
    {
        $filas = $this->database->queryAll(
            "SELECT DATE_FORMAT(pp.fecha_mostrada, '%Y-%m') AS mes, COUNT(*) AS respondidas,
                    COALESCE(SUM(pp.acerto = 1), 0) AS acertadas
             FROM partida_pregunta pp
             WHERE pp.pregunta_id = ? AND pp.acerto IS NOT NULL
               AND pp.fecha_mostrada >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
             GROUP BY mes",
            [$id]
        );
        $porMes = array_column($filas, null, "mes");

        $etiquetas = [];
        $valores = [];
        $primerMes = new \DateTimeImmutable("first day of this month");
        for ($i = 11; $i >= 0; $i--) {
            $mes = $primerMes->modify("-$i month");
            $etiquetas[] = EstadisticaModel::MESES[(int)$mes->format("n") - 1] . " " . $mes->format("y");
            $valores[] = $porMes[$mes->format("Y-m")] ?? ["respondidas" => 0, "acertadas" => 0];
        }
        return $this->bienYMal($etiquetas, $valores);
    }

    // Todos los reportes que tuvo, el más nuevo primero.
    private function reportesDeLaPregunta($id)
    {
        return $this->database->queryAll(
            "SELECT r.motivo, r.estado, r.fecha, jugador.usuario AS reportado_por, editor.usuario AS revisado_por
             FROM reporte r
             JOIN usuario jugador ON jugador.id = r.reportado_por
             LEFT JOIN usuario editor ON editor.id = r.revisado_por
             WHERE r.pregunta_id = ?
             ORDER BY r.fecha DESC",
            [$id]
        );
    }

    // Formato de los gráficos apilados de "bien" y "mal".
    private function bienYMal(array $etiquetas, array $valores)
    {
        return [
            "etiquetas" => array_values($etiquetas),
            "bien" => array_map(fn($v) => (int)$v["acertadas"], array_values($valores)),
            "mal" => array_map(fn($v) => (int)$v["respondidas"] - (int)$v["acertadas"], array_values($valores)),
        ];
    }

    private function porcentaje($parte, $total)
    {
        return $total > 0 ? (int)round($parte * 100 / $total) : 0;
    }
}
