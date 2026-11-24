<?php

namespace app\model;

// Números y gráficos del panel del administrador (pestaña Estadísticas y, después, el PDF).
// Todo se puede filtrar por período: hoy, esta semana (de lunes a domingo, como el ranking),
// este mes, este año o desde siempre.
// "Jugadores" son las cuentas validadas que no son admin ni el usuario "sistema" (id 1, autor
// de las preguntas iniciales). En un período, son los que jugaron al menos una partida en él;
// "Desde siempre" son todos los registrados.
class EstadisticaModel
{
    const PERIODOS = [
        "dia" => "Hoy",
        "semana" => "Semana",
        "mes" => "Mes",
        "anio" => "Año",
        "todo" => "Todo",
    ];

    // Desde cuándo cuenta cada período (null = sin límite).
    const DESDE_SQL = [
        "dia" => "CURDATE()",
        "semana" => PartidaModel::INICIO_SEMANA_SQL,
        "mes" => "DATE_FORMAT(CURDATE(), '%Y-%m-01')",
        "anio" => "MAKEDATE(YEAR(CURDATE()), 1)",
        "todo" => null,
    ];

    // Los del análisis del equipo (los mismos del filtro del ranking).
    const GRUPOS_DE_EDAD = RankingModel::GRUPOS_DE_EDAD;

    const SEXOS = [
        "femenino" => "Femenino",
        "masculino" => "Masculino",
        "no_informa" => "No informa",
    ];

    const MESES = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
    const DIAS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];

    // En el gráfico de países, los que quedan afuera de los primeros se suman en "Otros".
    const PAISES_EN_GRAFICO = 8;
    const USUARIOS_EN_TABLA = 50;

    const JUGADOR_SQL = "u.validado = 1 AND u.rol <> 'admin' AND u.id <> 1";

    private $database;
    // Los jugadores del período se usan en tres gráficos: se buscan una sola vez por pedido.
    private $jugadores = [];

    public function __construct($database)
    {
        $this->database = $database;
    }

    public static function periodoValido($periodo)
    {
        return isset(self::PERIODOS[$periodo]) ? $periodo : "semana";
    }

    // Todo lo del período en un solo arreglo: lo usan la pantalla, el JSON del AJAX y el PDF.
    public function resumen($periodo)
    {
        $periodo = self::periodoValido($periodo);

        return [
            "periodo" => $periodo,
            "totales" => $this->totales($periodo),
            "porPais" => $this->porPais($periodo),
            "porSexo" => $this->porSexo($periodo),
            "porEdad" => $this->porEdad($periodo),
            "partidasEnElTiempo" => $this->partidasEnElTiempo($periodo),
            "aciertosPorUsuario" => $this->aciertosPorUsuario($periodo),
        ];
    }

    private function totales($periodo)
    {
        $partidas = $this->database->queryOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(modo = 'shine'), 0) AS shine, COALESCE(SUM(modo = 'die'), 0) AS die
             FROM partida WHERE estado = 'terminada' AND " . $this->condicion("fecha_inicio", $periodo)
        );

        $preguntas = $this->database->queryOne(
            "SELECT COALESCE(SUM(estado = 'activa'), 0) AS activas,
                    COALESCE(SUM(creada_por <> 1 AND " . $this->condicion("fecha_alta", $periodo) . "), 0) AS creadas,
                    COALESCE(SUM(creada_por <> 1 AND estado = 'revision' AND " . $this->condicion("fecha_alta", $periodo) . "), 0) AS en_revision
             FROM pregunta"
        );

        $nuevos = $this->database->queryOne(
            "SELECT COUNT(*) AS total FROM usuario u WHERE " . self::JUGADOR_SQL . " AND " . $this->condicion("u.fecha_alta", $periodo)
        );

        $registrados = $this->database->queryOne(
            "SELECT COUNT(*) AS total FROM usuario u WHERE " . self::JUGADOR_SQL
        );

        return [
            "jugadores" => count($this->jugadoresDelPeriodo($periodo)),
            "registrados" => (int)$registrados["total"],
            "partidas" => (int)$partidas["total"],
            "partidasShine" => (int)$partidas["shine"],
            "partidasDie" => (int)$partidas["die"],
            "preguntasActivas" => (int)$preguntas["activas"],
            "preguntasCreadas" => (int)$preguntas["creadas"],
            "preguntasEnRevision" => (int)$preguntas["en_revision"],
            "usuariosNuevos" => (int)$nuevos["total"],
        ];
    }

    // Los jugadores que cuentan en el período (para los gráficos de país, sexo y edad).
    private function jugadoresDelPeriodo($periodo)
    {
        if (isset($this->jugadores[$periodo])) {
            return $this->jugadores[$periodo];
        }

        $sql = "SELECT u.id, u.sexo, YEAR(CURDATE()) - u.anio_nacimiento AS edad, p.nombre AS pais
                FROM usuario u JOIN pais p ON p.id = u.pais_id
                WHERE " . self::JUGADOR_SQL;

        if (self::DESDE_SQL[$periodo] !== null) {
            $sql .= " AND EXISTS (SELECT 1 FROM partida pa WHERE pa.usuario_id = u.id AND "
                . $this->condicion("pa.fecha_inicio", $periodo) . ")";
        }

        return $this->jugadores[$periodo] = $this->database->queryAll($sql);
    }

    private function porPais($periodo)
    {
        $cantidades = [];
        foreach ($this->jugadoresDelPeriodo($periodo) as $jugador) {
            $cantidades[$jugador["pais"]] = ($cantidades[$jugador["pais"]] ?? 0) + 1;
        }
        arsort($cantidades);

        $primeros = array_slice($cantidades, 0, self::PAISES_EN_GRAFICO, true);
        $otros = array_sum(array_slice($cantidades, self::PAISES_EN_GRAFICO));
        if ($otros > 0) {
            $primeros["Otros"] = $otros;
        }
        return $this->serie($primeros);
    }

    private function porSexo($periodo)
    {
        $cantidades = array_fill_keys(array_values(self::SEXOS), 0);
        foreach ($this->jugadoresDelPeriodo($periodo) as $jugador) {
            $cantidades[self::SEXOS[$jugador["sexo"]]]++;
        }
        return $this->serie($cantidades);
    }

    private function porEdad($periodo)
    {
        $cantidades = array_fill_keys(array_keys(self::GRUPOS_DE_EDAD), 0);
        foreach ($this->jugadoresDelPeriodo($periodo) as $jugador) {
            $cantidades[$this->grupoDeEdad((int)$jugador["edad"])]++;
        }
        return $this->serie($cantidades);
    }

    private function grupoDeEdad($edad)
    {
        foreach (self::GRUPOS_DE_EDAD as $grupo => [$desde, $hasta]) {
            if ($edad >= $desde && $edad <= $hasta) {
                return $grupo;
            }
        }
        // Por si alguien se registró con menos de 12: cuenta con los menores.
        return array_key_first(self::GRUPOS_DE_EDAD);
    }

    // Partidas terminadas, agrupadas según el período: por hora (hoy), por día (semana y mes)
    // o por mes (año y desde siempre). Los tramos sin partidas aparecen con 0.
    private function partidasEnElTiempo($periodo)
    {
        $formato = [
            "dia" => "%H",
            "semana" => "%Y-%m-%d",
            "mes" => "%Y-%m-%d",
            "anio" => "%Y-%m",
            "todo" => "%Y-%m",
        ][$periodo];

        $filas = $this->database->queryAll(
            "SELECT DATE_FORMAT(fecha_inicio, '$formato') AS tramo, COUNT(*) AS cantidad
             FROM partida WHERE estado = 'terminada' AND " . $this->condicion("fecha_inicio", $periodo) . "
             GROUP BY tramo ORDER BY tramo"
        );
        $porTramo = array_column($filas, "cantidad", "tramo");

        $tramos = [];
        foreach ($this->tramos($periodo, $porTramo) as $clave => $etiqueta) {
            $tramos[$etiqueta] = (int)($porTramo[$clave] ?? 0);
        }
        return $this->serie($tramos);
    }

    // Clave (como la arma DATE_FORMAT) => etiqueta que se ve en el gráfico.
    private function tramos($periodo, array $conDatos)
    {
        $tramos = [];
        $hoy = new \DateTimeImmutable("today");

        if ($periodo === "dia") {
            for ($hora = 0; $hora < 24; $hora++) {
                $tramos[sprintf("%02d", $hora)] = $hora . "h";
            }
        } elseif ($periodo === "semana") {
            $lunes = $hoy->modify("monday this week");
            for ($i = 0; $i < 7; $i++) {
                $dia = $lunes->modify("+$i day");
                $tramos[$dia->format("Y-m-d")] = self::DIAS[$i] . " " . $dia->format("j");
            }
        } elseif ($periodo === "mes") {
            $dias = (int)$hoy->format("t");
            for ($i = 1; $i <= $dias; $i++) {
                $tramos[$hoy->format("Y-m-") . sprintf("%02d", $i)] = (string)$i;
            }
        } elseif ($periodo === "anio") {
            for ($mes = 1; $mes <= 12; $mes++) {
                $tramos[$hoy->format("Y-") . sprintf("%02d", $mes)] = self::MESES[$mes - 1];
            }
        } else {
            // Desde el mes de la primera partida hasta este mes.
            $primero = $conDatos ? array_key_first($conDatos) : $hoy->format("Y-m");
            $mes = new \DateTimeImmutable($primero . "-01");
            $ultimo = $hoy->modify("first day of this month");
            while ($mes <= $ultimo) {
                $tramos[$mes->format("Y-m")] = self::MESES[(int)$mes->format("n") - 1] . " " . $mes->format("y");
                $mes = $mes->modify("+1 month");
            }
        }
        return $tramos;
    }

    // Porcentaje de respuestas correctas de cada jugador en el período (las preguntas que
    // respondió en sus partidas). Ordenado del mejor al peor; a igual porcentaje, el que más respondió.
    private function aciertosPorUsuario($periodo)
    {
        $filas = $this->database->queryAll(
            "SELECT u.usuario, COUNT(*) AS respondidas, SUM(pp.acerto) AS acertadas,
                    ROUND(SUM(pp.acerto) * 100 / COUNT(*)) AS porcentaje
             FROM partida_pregunta pp
             JOIN partida pa ON pa.id = pp.partida_id
             JOIN usuario u ON u.id = pa.usuario_id
             WHERE pp.acerto IS NOT NULL AND " . self::JUGADOR_SQL . " AND " . $this->condicion("pa.fecha_inicio", $periodo) . "
             GROUP BY u.id, u.usuario
             ORDER BY porcentaje DESC, respondidas DESC, u.usuario
             LIMIT " . self::USUARIOS_EN_TABLA
        );

        return array_map(fn($f) => [
            "usuario" => $f["usuario"],
            "respondidas" => (int)$f["respondidas"],
            "acertadas" => (int)$f["acertadas"],
            "porcentaje" => (int)$f["porcentaje"],
        ], $filas);
    }

    private function condicion($columna, $periodo)
    {
        $desde = self::DESDE_SQL[$periodo];
        return $desde === null ? "1 = 1" : "$columna >= $desde";
    }

    // Formato que entienden los gráficos: etiquetas y valores por separado, más el total.
    private function serie(array $cantidades)
    {
        return [
            "etiquetas" => array_keys($cantidades),
            "valores" => array_values($cantidades),
            "total" => array_sum($cantidades),
        ];
    }
}
