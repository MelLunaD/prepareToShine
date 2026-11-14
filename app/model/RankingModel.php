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

        return [
            "modo" => $modo,
            "esDie" => $modo === "die",
            "puntosSemana" => $puntos,
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
}
