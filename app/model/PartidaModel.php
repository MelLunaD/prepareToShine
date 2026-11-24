<?php

namespace app\model;

// Motor de la partida: nivel del jugador, sorteo de preguntas, corrección, puntos,
// checkpoints y almas. Todo el estado vive en la base (partida y partida_pregunta):
// recargar, cerrar la página o entrar desde otro dispositivo no cambia la pregunta
// ni frena el reloj. Las horas salen siempre de MySQL (NOW()), nunca de PHP, para
// que el reloj no dependa de la zona horaria de cada uno.
class PartidaModel
{
    const PREGUNTAS_POR_PARTIDA = 15;
    const SEGUNDOS_POR_PREGUNTA = 30;
    // Demora de la red entre que el reloj llega a 0 en pantalla y la respuesta llega al servidor.
    const SEGUNDOS_DE_MARGEN = 2;

    // Dificultad de cada una de las 15 preguntas según el nivel del jugador:
    // F fácil, M media, D difícil. Siempre van primero las más fáciles.
    const MEZCLA_POR_NIVEL = [
        "FFFFFFFFMMMMDDD", // N0
        "FFFFFFMMMMMMDDD", // N1
        "FFFFFMMMMMMDDDD", // N2
        "FFFFMMMMMDDDDDD", // N3
        "FFFMMMMMMDDDDDD", // N4
        "FFMMMMMMMDDDDDD", // N5
        "FFMMMMMMDDDDDDD", // N6
        "FMMMMMDDDDDDDDD", // N7
        "FMMMDDDDDDDDDDD", // N8
        "FMMDDDDDDDDDDDD", // N9
        "MMDDDDDDDDDDDDD", // N10
    ];
    const LETRA_A_DIFICULTAD = ["F" => "facil", "M" => "media", "D" => "dificil"];

    // Puntos por pregunta bien respondida, según su dificultad. Die paga el doble.
    const PUNTOS = ["facil" => 50, "media" => 100, "dificil" => 200];
    const MULTIPLICADOR = ["shine" => 1, "die" => 2];
    const BONUS_COMPLETA = 500;

    // Solo en Die: al responder bien estas preguntas, lo ganado hasta ahí queda asegurado
    // y el jugador se puede retirar con todo.
    const CHECKPOINTS_DIE = [5, 10];

    // El nivel sale del porcentaje de aciertos en las últimas 100 respuestas de ese modo.
    // Para que no salte de golpe con las primeras partidas (que empiezan con las fáciles),
    // se suman 20 respuestas "de arranque" al 35%: un jugador nuevo arranca en N3. Ese arranque
    // pesa cada vez menos y desaparece al llegar a 100 respuestas propias.
    const RESPUESTAS_PARA_NIVEL = 100;
    const RESPUESTAS_DE_ARRANQUE = 20;
    const PORCENTAJE_DE_ARRANQUE = 0.35;

    // Segundos que la carta elegida se queda mostrando la categoría antes de la pregunta.
    // El reloj de la pregunta arranca recién después, así no se descuentan de los 30.
    const SEGUNDOS_DE_REVELADO = 2;

    // Dificultad de una pregunta según la consigna: más del 70% de aciertos es fácil y menos
    // del 30% es difícil. Hasta tener MINIMO_PARA_CALCULAR respuestas usa la dificultad con la
    // que se cargó. Es el único lugar donde está ese número: las estadísticas del admin y los
    // textos de los formularios lo leen de acá.
    const MINIMO_PARA_CALCULAR = 5;
    const DIFICULTAD_SQL = "CASE
            WHEN p.veces_mostrada < " . self::MINIMO_PARA_CALCULAR . " THEN p.dificultad
            WHEN p.veces_acertada > p.veces_mostrada * 0.7 THEN 'facil'
            WHEN p.veces_acertada < p.veces_mostrada * 0.3 THEN 'dificil'
            ELSE 'media'
        END";

    // Lunes de esta semana: los puntos, los rankings y las almas se reinician cada lunes.
    const INICIO_SEMANA_SQL = "DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";

    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    // ---------- Partida en curso ----------

    // El jugador tiene a lo sumo una partida en curso (de Shine o de Die).
    public function enCurso($usuarioId)
    {
        return $this->database->queryOne(
            "SELECT id, usuario_id, modo, nivel FROM partida WHERE usuario_id = ? AND estado = 'en_curso' ORDER BY id DESC LIMIT 1",
            [$usuarioId]
        );
    }

    // Si hay una partida en curso la retoma; si no, crea una nueva del modo pedido.
    public function crear($usuarioId, $modo)
    {
        $enCurso = $this->enCurso($usuarioId);
        if ($enCurso !== null) {
            return (int)$enCurso["id"];
        }

        $this->database->execute(
            "INSERT INTO partida (usuario_id, modo, nivel) VALUES (?, ?, ?)",
            [$usuarioId, $modo, $this->calcularNivel($usuarioId, $modo)]
        );

        return (int)$this->database->lastInsertId();
    }

    public function calcularNivel($usuarioId, $modo)
    {
        // LIMIT no acepta parámetros con prepared statements nativos: va el número de la constante.
        $respuestas = $this->database->queryAll(
            "SELECT pp.acerto FROM partida_pregunta pp
             JOIN partida pa ON pa.id = pp.partida_id
             WHERE pa.usuario_id = ? AND pa.modo = ? AND pp.acerto IS NOT NULL
             ORDER BY pp.id DESC LIMIT " . self::RESPUESTAS_PARA_NIVEL,
            [$usuarioId, $modo]
        );

        $propias = count($respuestas);
        $arranque = self::RESPUESTAS_DE_ARRANQUE * (self::RESPUESTAS_PARA_NIVEL - $propias) / self::RESPUESTAS_PARA_NIVEL;
        $aciertos = count(array_filter($respuestas, fn($r) => (int)$r["acerto"] === 1)) + $arranque * self::PORCENTAJE_DE_ARRANQUE;

        return min(10, (int)floor($aciertos * 10 / ($propias + $arranque) + 1e-9));
    }

    // La pregunta mostrada y todavía sin responder, con sus opciones y el tiempo que le queda.
    // Las opciones salen mezcladas, pero siempre en el mismo orden para esa pregunta de esa
    // partida: recargar no las reordena.
    public function preguntaActual(array $partida)
    {
        $pregunta = $this->database->queryOne(
            "SELECT pp.id, pp.numero, pp.puntos, pp.pregunta_id, p.texto, p.juego, c.nombre AS categoria, c.color,
                    LEAST(?, GREATEST(0, ? - TIMESTAMPDIFF(SECOND, pp.fecha_mostrada, NOW()))) AS segundos_restantes,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), pp.fecha_mostrada)) AS segundos_para_empezar
             FROM partida_pregunta pp
             JOIN pregunta p ON p.id = pp.pregunta_id
             JOIN categoria c ON c.id = p.categoria_id
             WHERE pp.partida_id = ? AND pp.acerto IS NULL",
            [self::SEGUNDOS_POR_PREGUNTA, self::SEGUNDOS_POR_PREGUNTA, $partida["id"]]
        );

        if ($pregunta === null) {
            return null;
        }

        $pregunta["respuestas"] = $this->database->queryAll(
            "SELECT id, texto FROM respuesta WHERE pregunta_id = ? ORDER BY MD5(CONCAT(id, '-', ?))",
            [$pregunta["pregunta_id"], $pregunta["id"]]
        );

        return $pregunta;
    }

    // Si la pregunta en curso se quedó sin tiempo (el jugador cerró la página o la dejó
    // abierta), cuenta como error y la partida termina. Devuelve true si la cerró.
    public function cerrarSiVencio(array $partida)
    {
        $vencida = $this->database->queryOne(
            "SELECT id FROM partida_pregunta
             WHERE partida_id = ? AND acerto IS NULL AND TIMESTAMPDIFF(SECOND, fecha_mostrada, NOW()) > ?",
            [$partida["id"], self::SEGUNDOS_POR_PREGUNTA + self::SEGUNDOS_DE_MARGEN]
        );

        if ($vencida === null) {
            return false;
        }

        $this->responder($partida, null);
        return true;
    }

    // Puntos ganados en la partida hasta ahora y, en Die, cuántos están asegurados.
    public function puntosEnJuego(array $partida)
    {
        $filas = $this->database->queryAll(
            "SELECT numero, puntos FROM partida_pregunta WHERE partida_id = ? AND acerto = 1",
            [$partida["id"]]
        );

        $checkpoint = $this->ultimoCheckpoint($partida);
        $total = 0;
        $asegurados = 0;
        foreach ($filas as $fila) {
            $total += (int)$fila["puntos"];
            if ((int)$fila["numero"] <= $checkpoint) {
                $asegurados += (int)$fila["puntos"];
            }
        }

        return ["total" => $total, "asegurados" => $asegurados];
    }

    // Último checkpoint de Die que el jugador pasó en esta partida (0 si ninguno, y siempre 0 en Shine).
    private function ultimoCheckpoint(array $partida)
    {
        if ($partida["modo"] !== "die") {
            return 0;
        }

        $fila = $this->database->queryOne(
            "SELECT MAX(numero) AS numero FROM partida_pregunta
             WHERE partida_id = ? AND acerto = 1 AND numero IN (" . implode(", ", self::CHECKPOINTS_DIE) . ")",
            [$partida["id"]]
        );
        return (int)($fila["numero"] ?? 0);
    }

    public function preguntasRespondidas(array $partida)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS cantidad FROM partida_pregunta WHERE partida_id = ? AND acerto IS NOT NULL",
            [$partida["id"]]
        );
        return (int)$fila["cantidad"];
    }

    // Cantidad de cartas (una por categoría del modo, sin contar las que no tienen preguntas).
    public function cantidadDeCartas($modo)
    {
        return count($this->categoriasConPreguntas($modo));
    }

    // ---------- Jugar ----------

    // El jugador eligió una carta: se sortea la categoría y la pregunta, y empieza a correr el reloj.
    // Las cartas llegan boca abajo y sin datos al navegador, así que cuál tocó no cambia nada:
    // la categoría se sortea acá. Si ya hay una pregunta esperando respuesta, no hace nada.
    public function elegirCarta(array $partida)
    {
        if ($this->preguntaActual($partida) !== null) {
            return;
        }

        $numero = $this->preguntasRespondidas($partida) + 1;
        if ($numero > self::PREGUNTAS_POR_PARTIDA) {
            return;
        }

        $categorias = $this->categoriasConPreguntas($partida["modo"]);
        $categoriaId = $categorias[array_rand($categorias)];

        $letra = self::MEZCLA_POR_NIVEL[(int)$partida["nivel"]][$numero - 1];
        $pregunta = $this->sortearPregunta($partida["usuario_id"], $categoriaId, self::LETRA_A_DIFICULTAD[$letra]);

        $puntos = self::PUNTOS[$pregunta["dificultad"]] * self::MULTIPLICADOR[$partida["modo"]];

        // pp.puntos guarda lo que vale la pregunta; si se responde mal pasa a 0.
        // La clave única (partida_id, numero) evita duplicarla con un doble click.
        try {
            $this->database->execute(
                "INSERT INTO partida_pregunta (partida_id, pregunta_id, numero, puntos, fecha_mostrada)
                 VALUES (?, ?, ?, ?, NOW() + INTERVAL " . self::SEGUNDOS_DE_REVELADO . " SECOND)",
                [$partida["id"], $pregunta["id"], $numero, $puntos]
            );
        } catch (\PDOException $e) {
            return;
        }

        $this->database->execute("UPDATE pregunta SET veces_mostrada = veces_mostrada + 1 WHERE id = ?", [$pregunta["id"]]);
    }

    // Corrige la respuesta de la pregunta en curso. $respuestaId null = no respondió a tiempo.
    // Devuelve qué pasó, o null si no había pregunta esperando respuesta (doble envío, recarga).
    public function responder(array $partida, $respuestaId)
    {
        $actual = $this->database->queryOne(
            "SELECT pp.id, pp.numero, pp.pregunta_id,
                    TIMESTAMPDIFF(SECOND, pp.fecha_mostrada, NOW()) AS segundos
             FROM partida_pregunta pp WHERE pp.partida_id = ? AND pp.acerto IS NULL",
            [$partida["id"]]
        );
        if ($actual === null) {
            return null;
        }

        $aTiempo = (int)$actual["segundos"] <= self::SEGUNDOS_POR_PREGUNTA + self::SEGUNDOS_DE_MARGEN;

        // La respuesta tiene que ser una de las opciones de esa pregunta.
        $elegida = null;
        if ($respuestaId !== null && $aTiempo) {
            $elegida = $this->database->queryOne(
                "SELECT id, es_correcta FROM respuesta WHERE id = ? AND pregunta_id = ?",
                [$respuestaId, $actual["pregunta_id"]]
            );
        }
        $acerto = $elegida !== null && (int)$elegida["es_correcta"] === 1;

        // "acerto IS NULL" en el WHERE: si llegan dos respuestas juntas, solo cuenta la primera.
        $actualizadas = $this->database->execute(
            "UPDATE partida_pregunta SET respuesta_id = ?, acerto = ?, puntos = IF(?, puntos, 0)
             WHERE id = ? AND acerto IS NULL",
            [$elegida["id"] ?? null, (int)$acerto, (int)$acerto, $actual["id"]]
        );
        if ($actualizadas === 0) {
            return null;
        }

        $this->database->execute(
            "UPDATE pregunta SET veces_acertada = veces_acertada + ? WHERE id = ?",
            [(int)$acerto, $actual["pregunta_id"]]
        );
        $this->database->execute(
            "UPDATE usuario SET preguntas_respondidas = preguntas_respondidas + 1, preguntas_acertadas = preguntas_acertadas + ? WHERE id = ?",
            [(int)$acerto, $partida["usuario_id"]]
        );

        $numero = (int)$actual["numero"];
        $almasRecuperadas = 0;
        if ($acerto && $partida["modo"] === "die") {
            $almasRecuperadas = $this->recuperarAlmas($partida, $actual["id"], $numero);
        }

        $terminada = false;
        if (!$acerto) {
            $this->terminar($partida, "muerte");
            $terminada = true;
        } elseif ($numero === self::PREGUNTAS_POR_PARTIDA) {
            $this->terminar($partida, "completa");
            $terminada = true;
        }

        $puntos = $this->database->queryOne("SELECT puntos FROM partida_pregunta WHERE id = ?", [$actual["id"]]);

        return [
            "acerto" => $acerto,
            "a_tiempo" => $aTiempo,
            "numero" => $numero,
            "puntos" => (int)$puntos["puntos"] - $almasRecuperadas,
            "almas_recuperadas" => $almasRecuperadas,
            "terminada" => $terminada,
        ];
    }

    // En Die, al responder bien un checkpoint, el jugador puede llevarse todo.
    public function puedeRetirarse(array $partida)
    {
        if ($partida["modo"] !== "die" || $this->preguntaActual($partida) !== null) {
            return false;
        }

        $ultima = $this->database->queryOne(
            "SELECT numero, acerto FROM partida_pregunta WHERE partida_id = ? ORDER BY numero DESC LIMIT 1",
            [$partida["id"]]
        );

        return $ultima !== null && (int)$ultima["acerto"] === 1 && in_array((int)$ultima["numero"], self::CHECKPOINTS_DIE, true);
    }

    public function retirarse(array $partida)
    {
        if (!$this->puedeRetirarse($partida)) {
            return false;
        }

        $this->terminar($partida, "retiro");
        return true;
    }

    // ---------- Almas (solo Die) ----------

    // Almas que el jugador dejó tiradas esta semana y todavía puede recuperar:
    // cuántas son y en qué pregunta quedaron. Las de semanas anteriores se pierden.
    public function almasEsperando($usuarioId)
    {
        $this->database->execute(
            "UPDATE partida SET almas_estado = 'perdidas'
             WHERE usuario_id = ? AND almas_estado = 'esperando' AND fecha_inicio < " . self::INICIO_SEMANA_SQL,
            [$usuarioId]
        );

        return $this->database->queryOne(
            "SELECT pa.id AS partida_id, pa.almas, pp.numero
             FROM partida pa
             JOIN partida_pregunta pp ON pp.partida_id = pa.id AND pp.acerto = 0
             WHERE pa.usuario_id = ? AND pa.almas_estado = 'esperando'
             ORDER BY pa.id DESC LIMIT 1",
            [$usuarioId]
        );
    }

    // Si el jugador acaba de responder bien la pregunta donde había muerto, recupera sus almas:
    // se suman a lo que vale esta pregunta y, como el resto, quedan en juego hasta el próximo checkpoint.
    private function recuperarAlmas(array $partida, $partidaPreguntaId, $numero)
    {
        $almas = $this->almasEsperando($partida["usuario_id"]);
        if ($almas === null || (int)$almas["partida_id"] === (int)$partida["id"] || (int)$almas["numero"] !== $numero) {
            return 0;
        }

        $this->database->execute("UPDATE partida SET almas_estado = 'recuperadas' WHERE id = ?", [$almas["partida_id"]]);
        $this->database->execute(
            "UPDATE partida_pregunta SET puntos = puntos + ? WHERE id = ?",
            [$almas["almas"], $partidaPreguntaId]
        );

        return (int)$almas["almas"];
    }

    // ---------- Fin de la partida ----------

    // $como: "muerte" (error o tiempo agotado), "retiro" (Die, en un checkpoint) o "completa" (las 15 bien).
    private function terminar(array $partida, $como)
    {
        $puntos = $this->puntosEnJuego($partida);
        $multiplicador = self::MULTIPLICADOR[$partida["modo"]];

        $puntaje = $puntos["total"];
        $almas = 0;

        if ($como === "completa") {
            $puntaje += self::BONUS_COMPLETA * $multiplicador;
        } elseif ($como === "muerte" && $partida["modo"] === "die") {
            // En Die, al morir solo cuenta lo asegurado; el resto queda tirado como almas.
            $puntaje = $puntos["asegurados"];
            $almas = $puntos["total"] - $puntos["asegurados"];
        }

        // Morir sin haber recuperado las almas anteriores las pierde para siempre.
        if ($como === "muerte" && $partida["modo"] === "die") {
            $this->database->execute(
                "UPDATE partida SET almas_estado = 'perdidas' WHERE usuario_id = ? AND almas_estado = 'esperando' AND id <> ?",
                [$partida["usuario_id"], $partida["id"]]
            );
        }

        $this->database->execute(
            "UPDATE partida SET estado = 'terminada', fecha_fin = NOW(), puntaje = ?, almas = ?, almas_estado = ?
             WHERE id = ? AND estado = 'en_curso'",
            [$puntaje, $almas, $almas > 0 ? "esperando" : "ninguna", $partida["id"]]
        );
    }

    // Lo que muestra la pantalla de fin de partida. Solo la del propio jugador.
    public function resultado($partidaId, $usuarioId)
    {
        $partida = $this->database->queryOne(
            "SELECT id, modo, nivel, puntaje, almas, almas_estado, estado FROM partida WHERE id = ? AND usuario_id = ? AND estado = 'terminada'",
            [$partidaId, $usuarioId]
        );
        if ($partida === null) {
            return null;
        }

        $partida["respondidas"] = $this->preguntasRespondidas($partida);
        $partida["error"] = $this->database->queryOne(
            "SELECT pp.numero, pp.respuesta_id, p.texto AS pregunta, r.texto AS correcta, elegida.texto AS elegida
             FROM partida_pregunta pp
             JOIN pregunta p ON p.id = pp.pregunta_id
             JOIN respuesta r ON r.pregunta_id = p.id AND r.es_correcta = 1
             LEFT JOIN respuesta elegida ON elegida.id = pp.respuesta_id
             WHERE pp.partida_id = ? AND pp.acerto = 0",
            [$partidaId]
        );

        return $partida;
    }

    // Últimas partidas terminadas del jugador en un modo, para el lobby.
    public function historial($usuarioId, $modo, $cantidad = 10)
    {
        return $this->database->queryAll(
            "SELECT pa.id, pa.puntaje, pa.almas, pa.fecha_inicio,
                    COUNT(pp.id) AS respondidas,
                    SUM(pp.acerto = 0) AS errores
             FROM partida pa
             LEFT JOIN partida_pregunta pp ON pp.partida_id = pa.id AND pp.acerto IS NOT NULL
             WHERE pa.usuario_id = ? AND pa.modo = ? AND pa.estado = 'terminada'
             GROUP BY pa.id
             ORDER BY pa.fecha_inicio DESC, pa.id DESC
             LIMIT " . (int)$cantidad,
            [$usuarioId, $modo]
        );
    }

    // ---------- Sorteo ----------

    private function categoriasConPreguntas($modo)
    {
        $filas = $this->database->queryAll(
            "SELECT DISTINCT c.id FROM categoria c
             JOIN pregunta p ON p.categoria_id = c.id AND p.estado = 'activa'
             WHERE c.fase = ?",
            [$modo]
        );
        return array_map(fn($f) => (int)$f["id"], $filas);
    }

    // Busca una pregunta activa de la categoría y la dificultad pedidas que el jugador no haya visto.
    // Si no queda ninguna, prueba con las dificultades vecinas; si ya vio todas las de la
    // categoría, se permite repetir.
    private function sortearPregunta($usuarioId, $categoriaId, $dificultad)
    {
        $vecinas = [
            "facil" => ["facil", "media", "dificil"],
            "media" => ["media", "facil", "dificil"],
            "dificil" => ["dificil", "media", "facil"],
        ];

        foreach ([true, false] as $sinRepetir) {
            foreach ($vecinas[$dificultad] as $intento) {
                $sql = "SELECT p.id, " . self::DIFICULTAD_SQL . " AS dificultad
                        FROM pregunta p
                        WHERE p.estado = 'activa' AND p.categoria_id = ?
                          AND " . self::DIFICULTAD_SQL . " = ?";
                $params = [$categoriaId, $intento];

                if ($sinRepetir) {
                    $sql .= " AND p.id NOT IN (
                                SELECT pp.pregunta_id FROM partida_pregunta pp
                                JOIN partida pa ON pa.id = pp.partida_id
                                WHERE pa.usuario_id = ?)";
                    $params[] = $usuarioId;
                }

                $pregunta = $this->database->queryOne($sql . " ORDER BY RAND() LIMIT 1", $params);
                if ($pregunta !== null) {
                    return $pregunta;
                }
            }
        }

        // No debería pasar: categoriasConPreguntas() solo devuelve categorías con preguntas activas.
        throw new \RuntimeException("No hay preguntas activas en la categoría $categoriaId");
    }
}
