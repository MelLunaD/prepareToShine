<?php

namespace app\model;

// Puntos de la semana y rangos. Shine y Die llevan todo por separado.
// Nada de esto se guarda: se calcula desde las partidas cada vez que hace falta,
// así sirve igual para el lobby, el ranking y los reportes del administrador.
class RankingModel
{
    // Las almas se enfrían: el primer día sin jugar no pasa nada; a las 00:00 del segundo
    // se pierde el 5% de los puntos de la semana, y desde ahí un 10% más cada día.
    const DIAS_SIN_ENFRIAR = 1;
    const ENFRIAMIENTO_PRIMER_DIA = 0.05;
    const ENFRIAMIENTO_POR_DIA = 0.10;

    // Cuántos jugadores muestra cada ranking según el período: top 10 semanal, top 20 histórico
    // (el propio jugador se agrega abajo si quedó afuera).
    const CANTIDAD_EN_RANKING = ["semana" => 10, "historico" => 20];

    // Grupos de edad del filtro (los que definió el equipo en el análisis).
    const GRUPOS_DE_EDAD = [
        "12-17" => [12, 17],
        "18-39" => [18, 39],
        "40-59" => [40, 59],
        "60+" => [60, 200],
    ];

    // Lunes de la semana pasada: el salón de la fama muestra el podio de esa semana.
    const INICIO_SEMANA_PASADA_SQL = "DATE_SUB(" . PartidaModel::INICIO_SEMANA_SQL . ", INTERVAL 7 DAY)";

    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    // Puntos de esta semana (desde el lunes) del jugador en un modo, ya enfriados.
    public function puntosSemana($usuarioId, $modo)
    {
        $partidas = $this->database->queryAll(
            "SELECT DATE(fecha_inicio) AS dia, puntaje FROM partida
             WHERE usuario_id = ? AND modo = ? AND fecha_inicio >= " . PartidaModel::INICIO_SEMANA_SQL . "
             ORDER BY fecha_inicio",
            [$usuarioId, $modo]
        );
        $hoy = $this->database->queryOne("SELECT CURDATE() AS hoy")["hoy"];

        return self::conEnfriamiento($partidas, $hoy);
    }

    // $partidas: [["dia" => "Y-m-d", "puntaje" => int], ...] en orden. Cada partida empezada
    // cuenta como jugar ese día, aunque haya terminado con 0 puntos.
    public static function conEnfriamiento(array $partidas, $hoy)
    {
        $puntos = 0;
        $ultimoDia = null;

        foreach ($partidas as $partida) {
            if ($ultimoDia !== null) {
                $puntos = self::enfriar($puntos, $ultimoDia, $partida["dia"]);
            }
            $puntos += (int)$partida["puntaje"];
            $ultimoDia = $partida["dia"];
        }

        return $ultimoDia === null ? 0 : self::enfriar($puntos, $ultimoDia, $hoy);
    }

    // Lo más alto que llegaron los puntos en esas partidas (de una misma semana), con el
    // enfriamiento entre días. Lo usa "Quiero moderar": "llegó a 4000" es haberlos tocado alguna vez.
    public static function maximoConEnfriamiento(array $partidas)
    {
        $puntos = 0;
        $maximo = 0;
        $ultimoDia = null;

        foreach ($partidas as $partida) {
            if ($ultimoDia !== null) {
                $puntos = self::enfriar($puntos, $ultimoDia, $partida["dia"]);
            }
            $puntos += (int)$partida["puntaje"];
            $maximo = max($maximo, $puntos);
            $ultimoDia = $partida["dia"];
        }

        return $maximo;
    }

    // Aplica el enfriamiento de cada medianoche entre el último día jugado y $hasta.
    private static function enfriar($puntos, $desde, $hasta)
    {
        $dias = (int)(new \DateTime($desde))->diff(new \DateTime($hasta))->format("%a");

        for ($dia = self::DIAS_SIN_ENFRIAR + 1; $dia <= $dias; $dia++) {
            $perdida = $dia === self::DIAS_SIN_ENFRIAR + 1 ? self::ENFRIAMIENTO_PRIMER_DIA : self::ENFRIAMIENTO_POR_DIA;
            $puntos = (int)floor($puntos * (1 - $perdida));
        }

        return $puntos;
    }

    // Partidas empezadas esta semana (desde el lunes) en un modo.
    public function partidasSemana($usuarioId, $modo)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS cantidad FROM partida
             WHERE usuario_id = ? AND modo = ? AND fecha_inicio >= " . PartidaModel::INICIO_SEMANA_SQL,
            [$usuarioId, $modo]
        );
        return (int)$fila["cantidad"];
    }

    // Todo lo que se muestra del jugador en un modo: puntos y partidas de la semana, y su rango
    // con color, brillo e ícono. Lo usan el lobby y el perfil (propio y público).
    public function resumen($usuarioId, $modo)
    {
        $puntos = $this->puntosSemana($usuarioId, $modo);
        $rango = $this->rango($modo, $puntos);
        $actual = $rango["actual"];
        $siguiente = $rango["siguiente"];

        $racha = $this->racha($usuarioId, $modo);

        return [
            "modo" => $modo,
            "esDie" => $modo === "die",
            "puntosSemana" => $puntos,
            "rachaActual" => $racha["actual"],
            "partidasSemana" => $this->partidasSemana($usuarioId, $modo),
            "rango" => $actual["nombre"] ?? null,
            "colorRango" => $actual["color"] ?? null,
            "rangoBrilla" => (bool)($actual["brilla"] ?? false),
            "iconoRango" => !empty($actual["icono"]) ? "/public/img/rangos/" . $actual["icono"] : null,
            "siguienteRango" => $siguiente
                ? ["nombre" => $siguiente["nombre"], "faltan" => (int)$siguiente["puntos_minimos"] - $puntos]
                : null,
        ];
    }

    // Rango que corresponde a esos puntos, y el siguiente (null si ya está en el último).
    public function rango($modo, $puntos)
    {
        $actual = $this->database->queryOne(
            "SELECT id, nombre, puntos_minimos, color, brilla, icono FROM rango WHERE modo = ? AND puntos_minimos <= ? ORDER BY puntos_minimos DESC LIMIT 1",
            [$modo, $puntos]
        );
        $siguiente = $this->database->queryOne(
            "SELECT id, nombre, puntos_minimos FROM rango WHERE modo = ? AND puntos_minimos > ? ORDER BY puntos_minimos LIMIT 1",
            [$modo, $puntos]
        );

        return ["actual" => $actual, "siguiente" => $siguiente];
    }

    // ---------- Racha ----------

    // Racha de un jugador en un modo: respuestas correctas seguidas a lo largo de todas sus
    // partidas. La corta un error (o que se acabe el tiempo); retirarse en una hoguera no la corta.
    public function racha($usuarioId, $modo)
    {
        $respuestas = $this->database->queryAll(
            "SELECT pp.acerto, pp.fecha_mostrada AS fecha
             FROM partida_pregunta pp JOIN partida pa ON pa.id = pp.partida_id
             WHERE pa.usuario_id = ? AND pa.modo = ? AND pp.acerto IS NOT NULL
             ORDER BY pa.fecha_inicio, pa.id, pp.numero",
            [$usuarioId, $modo]
        );

        return self::calcularRacha($respuestas);
    }

    // $respuestas: [["acerto" => 0|1, "fecha" => ...], ...] en el orden en que se respondieron.
    // Devuelve la racha actual, la mejor y cuándo se llegó a la mejor (para desempatar).
    public static function calcularRacha(array $respuestas)
    {
        $actual = 0;
        $mejor = 0;
        $fechaMejor = null;

        foreach ($respuestas as $respuesta) {
            if ((int)$respuesta["acerto"] === 1) {
                $actual++;
                if ($actual > $mejor) {
                    $mejor = $actual;
                    $fechaMejor = $respuesta["fecha"];
                }
            } else {
                $actual = 0;
            }
        }

        return ["actual" => $actual, "mejor" => $mejor, "fechaMejor" => $fechaMejor];
    }

    // ---------- Ranking de todos ----------

    // Todos los jugadores que jugaron al menos una partida del modo, con sus números de la
    // semana, de la semana pasada y de siempre. Son tres consultas en total (jugadores, partidas
    // y respuestas), no una por jugador: el enfriamiento y la racha se calculan acá en PHP.
    public function jugadores($modo)
    {
        $jugadores = [];
        $filas = $this->database->queryAll(
            "SELECT u.id, u.usuario, u.nombre_completo, u.foto, u.anio_nacimiento,
                    YEAR(CURDATE()) - u.anio_nacimiento AS edad,
                    p.codigo AS pais_codigo, p.nombre AS pais_nombre
             FROM usuario u JOIN pais p ON p.id = u.pais_id
             WHERE u.id IN (SELECT usuario_id FROM partida WHERE modo = ?)",
            [$modo]
        );
        foreach ($filas as $fila) {
            $fila["semana"] = self::numerosVacios();
            $fila["pasada"] = self::numerosVacios();
            $fila["historico"] = self::numerosVacios();
            // Cómo estaba al terminar el día de ayer, para las flechas de subida y bajada.
            $fila["ayer"] = ["semana" => self::numerosVacios(), "historico" => self::numerosVacios()];
            $jugadores[(int)$fila["id"]] = $fila;
        }

        // Una fila por partida. "ganada" = respondió bien las 15.
        $partidas = $this->database->queryAll(
            "SELECT pa.usuario_id, pa.puntaje, DATE(pa.fecha_inicio) AS dia,
                    COALESCE(pa.fecha_fin, pa.fecha_inicio) AS fecha,
                    CASE WHEN pa.fecha_inicio >= " . PartidaModel::INICIO_SEMANA_SQL . " THEN 'semana'
                         WHEN pa.fecha_inicio >= " . self::INICIO_SEMANA_PASADA_SQL . " THEN 'pasada'
                         ELSE NULL END AS cual,
                    (SELECT COUNT(*) FROM partida_pregunta pp WHERE pp.partida_id = pa.id AND pp.acerto = 1) = ? AS ganada
             FROM partida pa
             WHERE pa.modo = ?
             ORDER BY pa.fecha_inicio, pa.id",
            [PartidaModel::PREGUNTAS_POR_PARTIDA, $modo]
        );

        $fechas = $this->database->queryOne(
            "SELECT CURDATE() AS hoy, DATE_SUB(CURDATE(), INTERVAL 1 DAY) AS ayer,
                    DATE_SUB(" . PartidaModel::INICIO_SEMANA_SQL . ", INTERVAL 1 DAY) AS domingo"
        );

        $porSemana = [];
        foreach ($partidas as $partida) {
            $id = (int)$partida["usuario_id"];
            if (!isset($jugadores[$id])) {
                continue;
            }
            // Las partidas empezadas antes de hoy también cuentan para "ayer".
            $antesDeHoy = $partida["dia"] < $fechas["hoy"];
            self::sumarPartida($jugadores[$id]["historico"], $partida);
            if ($antesDeHoy) {
                self::sumarPartida($jugadores[$id]["ayer"]["historico"], $partida);
            }
            if ($partida["cual"] !== null) {
                self::sumarPartida($jugadores[$id][$partida["cual"]], $partida);
                $porSemana[$id][$partida["cual"]][] = $partida;
                if ($partida["cual"] === "semana" && $antesDeHoy) {
                    self::sumarPartida($jugadores[$id]["ayer"]["semana"], $partida);
                    $porSemana[$id]["semanaAyer"][] = $partida;
                }
            }
        }

        // Los puntos de la semana llevan enfriamiento: hasta hoy los de esta semana, hasta ayer
        // los de esta semana como estaban ayer, y hasta el domingo los de la pasada (así quedaron
        // cuando cerró esa semana).
        foreach ($porSemana as $id => $semanas) {
            if (isset($semanas["semana"])) {
                $jugadores[$id]["semana"]["puntos"] = self::conEnfriamiento($semanas["semana"], $fechas["hoy"]);
            }
            if (isset($semanas["semanaAyer"])) {
                $jugadores[$id]["ayer"]["semana"]["puntos"] = self::conEnfriamiento($semanas["semanaAyer"], $fechas["ayer"]);
            }
            if (isset($semanas["pasada"])) {
                $jugadores[$id]["pasada"]["puntos"] = self::conEnfriamiento($semanas["pasada"], $fechas["domingo"]);
            }
        }

        $respuestas = $this->database->queryAll(
            "SELECT pa.usuario_id, pp.acerto, pp.fecha_mostrada AS fecha
             FROM partida_pregunta pp JOIN partida pa ON pa.id = pp.partida_id
             WHERE pa.modo = ? AND pp.acerto IS NOT NULL
             ORDER BY pa.usuario_id, pa.fecha_inicio, pa.id, pp.numero",
            [$modo]
        );
        $porJugador = [];
        $porJugadorAyer = [];
        foreach ($respuestas as $respuesta) {
            $porJugador[(int)$respuesta["usuario_id"]][] = $respuesta;
            if (substr($respuesta["fecha"], 0, 10) < $fechas["hoy"]) {
                $porJugadorAyer[(int)$respuesta["usuario_id"]][] = $respuesta;
            }
        }
        foreach ($jugadores as $id => $jugador) {
            $jugadores[$id]["racha"] = self::calcularRacha($porJugador[$id] ?? []);
            $jugadores[$id]["ayer"]["racha"] = self::calcularRacha($porJugadorAyer[$id] ?? []);
        }

        return $jugadores;
    }

    private static function numerosVacios()
    {
        return ["puntos" => 0, "partidas" => 0, "ganadas" => 0, "fechaPuntos" => null, "fechaGanadas" => null];
    }

    // Suma una partida a los números de un período. Las fechas guardan cuándo se llegó al número
    // actual (la última partida que sumó), para desempatar: gana el que llegó antes.
    private static function sumarPartida(array &$numeros, array $partida)
    {
        $numeros["partidas"]++;
        $numeros["puntos"] += (int)$partida["puntaje"];
        if ((int)$partida["puntaje"] > 0) {
            $numeros["fechaPuntos"] = $partida["fecha"];
        }
        if ((int)$partida["ganada"] === 1) {
            $numeros["ganadas"]++;
            $numeros["fechaGanadas"] = $partida["fecha"];
        }
    }

    // ---------- Flechas de subida y bajada ----------

    // Puesto que tenía cada jugador al terminar el día de ayer en ese ranking (con los mismos
    // filtros): [id => puesto]. El que no aparece en el resultado es "nuevo".
    public static function puestosDeAyer(array $jugadores, $periodo, $tipo)
    {
        $comoAyer = array_map(function ($jugador) {
            $jugador["semana"] = $jugador["ayer"]["semana"];
            $jugador["historico"] = $jugador["ayer"]["historico"];
            $jugador["racha"] = $jugador["ayer"]["racha"];
            return $jugador;
        }, $jugadores);

        $puestos = [];
        foreach (self::ordenar($comoAyer, $periodo, $tipo) as $jugador) {
            $puestos[(int)$jugador["id"]] = $jugador["puesto"];
        }
        return $puestos;
    }

    // Deja solo a los jugadores del país (código ISO) y del grupo de edad pedidos. null = todos.
    public static function filtrar(array $jugadores, $paisCodigo, $grupoEdad)
    {
        $edades = self::GRUPOS_DE_EDAD[$grupoEdad] ?? null;

        return array_filter($jugadores, function ($jugador) use ($paisCodigo, $edades) {
            if ($paisCodigo !== null && $jugador["pais_codigo"] !== $paisCodigo) {
                return false;
            }
            if ($edades !== null && ((int)$jugador["edad"] < $edades[0] || (int)$jugador["edad"] > $edades[1])) {
                return false;
            }
            return true;
        });
    }

    // Ordena a los jugadores para un ranking y les pone el puesto (nunca se comparte).
    // $periodo: "semana", "pasada" o "historico". $tipo: "puntos", "ganadas" o "racha".
    //   Puntos:  más puntos, después menos partidas, después el que llegó antes.
    //   Ganadas: más ganadas, después menos partidas, después el que llegó antes.
    //   Racha:   la más larga, después el que llegó antes (solo histórica).
    // Solo entran los que tienen algo para mostrar en ese ranking.
    public static function ordenar(array $jugadores, $periodo, $tipo)
    {
        $lista = array_values(array_filter($jugadores, function ($jugador) use ($periodo, $tipo) {
            if ($tipo === "racha") {
                return $jugador["racha"]["mejor"] > 0;
            }
            if ($tipo === "ganadas") {
                return $jugador[$periodo]["ganadas"] > 0;
            }
            return $jugador[$periodo]["partidas"] > 0;
        }));

        usort($lista, function ($a, $b) use ($periodo, $tipo) {
            if ($tipo === "racha") {
                return [$b["racha"]["mejor"], $a["racha"]["fechaMejor"], $a["id"]]
                    <=> [$a["racha"]["mejor"], $b["racha"]["fechaMejor"], $b["id"]];
            }

            $x = $a[$periodo];
            $y = $b[$periodo];
            $fecha = $tipo === "ganadas" ? "fechaGanadas" : "fechaPuntos";

            // Sin fecha (0 puntos) va al final de los empatados.
            return [$y[$tipo], $x["partidas"], $x[$fecha] ?? "9999", $a["id"]]
                <=> [$x[$tipo], $y["partidas"], $y[$fecha] ?? "9999", $b["id"]];
        });

        foreach ($lista as $i => $jugador) {
            $lista[$i]["puesto"] = $i + 1;
        }

        return $lista;
    }

    // Todos los rangos de un modo, de menor a mayor: para ponerle el rango a cada fila del
    // ranking sin hacer una consulta por jugador.
    public function rangos($modo)
    {
        return $this->database->queryAll(
            "SELECT nombre, puntos_minimos, color, brilla, icono FROM rango WHERE modo = ? ORDER BY puntos_minimos",
            [$modo]
        );
    }

    public static function rangoPara(array $rangos, $puntos)
    {
        $elegido = null;
        foreach ($rangos as $rango) {
            if ((int)$rango["puntos_minimos"] <= $puntos) {
                $elegido = $rango;
            }
        }
        return $elegido;
    }

    // Países que tienen jugadores en el modo, para el filtro.
    public static function paises(array $jugadores)
    {
        $paises = [];
        foreach ($jugadores as $jugador) {
            $paises[$jugador["pais_codigo"]] = $jugador["pais_nombre"];
        }
        asort($paises);
        return $paises;
    }
}
