<?php

namespace app\model;

use PDOException;

// Reportes de preguntas. El jugador reporta una pregunta que YA respondió (nunca la que tiene
// pendiente): un solo reporte la manda a cuarentena y deja de salir en el juego hasta que un
// editor la revise. Cada jugador tiene 3 reportes por semana y puede reportar cada pregunta una vez.
class ReporteModel
{
    const POR_SEMANA = 3;

    // Motivos fijos: el jugador no escribe texto. La clave es lo que se guarda en reporte.motivo (ENUM).
    const MOTIVOS = [
        "correcta_mal" => "La respuesta correcta está mal",
        "varias_correctas" => "Hay más de una respuesta correcta",
        "ninguna_correcta" => "Ninguna opción es correcta",
        "redaccion" => "La pregunta tiene errores de ortografía o redacción",
        "confusa" => "La pregunta es confusa o ambigua",
        "desactualizada" => "El dato está desactualizado",
        "categoria" => "La categoría o el juego no corresponden",
        "regala" => "La pregunta regala la respuesta",
        "ofensiva" => "Contenido ofensivo o inapropiado",
    ];

    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    public function restantes($usuarioId)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS cantidad FROM reporte
             WHERE reportado_por = ? AND fecha >= " . PartidaModel::INICIO_SEMANA_SQL,
            [$usuarioId]
        );
        return max(0, self::POR_SEMANA - (int)$fila["cantidad"]);
    }

    // La última pregunta que el jugador respondió en esta partida (bien, mal o sin tiempo),
    // para el botón "Reportar pregunta". null si todavía no respondió ninguna.
    public function ultimaRespondida($partidaId, $usuarioId)
    {
        return $this->database->queryOne(
            "SELECT p.id, p.texto,
                    EXISTS (SELECT 1 FROM reporte r WHERE r.pregunta_id = p.id AND r.reportado_por = ?) AS ya_reportada
             FROM partida_pregunta pp
             JOIN partida pa ON pa.id = pp.partida_id
             JOIN pregunta p ON p.id = pp.pregunta_id
             WHERE pp.partida_id = ? AND pa.usuario_id = ? AND pp.acerto IS NOT NULL
             ORDER BY pp.numero DESC LIMIT 1",
            [$usuarioId, $partidaId, $usuarioId]
        );
    }

    // Devuelve ["ok" => bool, "mensaje" => texto para el jugador, "restantes" => int].
    public function reportar($usuarioId, $preguntaId, $motivo)
    {
        if (!isset(self::MOTIVOS[$motivo])) {
            return $this->respuesta(false, "Elegí un motivo de la lista.", $usuarioId);
        }

        // Solo una pregunta que ya respondió: así no se puede reportar la que tiene en pantalla
        // (ni una cualquiera cambiando el id en el HTML).
        $respondida = $this->database->queryOne(
            "SELECT p.estado FROM partida_pregunta pp
             JOIN partida pa ON pa.id = pp.partida_id
             JOIN pregunta p ON p.id = pp.pregunta_id
             WHERE pa.usuario_id = ? AND pp.pregunta_id = ? AND pp.acerto IS NOT NULL
             LIMIT 1",
            [$usuarioId, $preguntaId]
        );
        if ($respondida === null) {
            return $this->respuesta(false, "Solo podés reportar preguntas que ya respondiste.", $usuarioId);
        }
        if ($respondida["estado"] === "inactiva") {
            return $this->respuesta(false, "Esa pregunta ya fue dada de baja. ¡Gracias igual!", $usuarioId);
        }

        if ($this->restantes($usuarioId) === 0) {
            return $this->respuesta(false, "Ya usaste tus " . self::POR_SEMANA . " reportes de esta semana. El lunes se renuevan.", $usuarioId);
        }

        // La clave única (pregunta_id, reportado_por) frena el segundo reporte del mismo jugador.
        try {
            $this->database->execute(
                "INSERT INTO reporte (pregunta_id, reportado_por, motivo) VALUES (?, ?, ?)",
                [$preguntaId, $usuarioId, $motivo]
            );
        } catch (PDOException $e) {
            return $this->respuesta(false, "Ya reportaste esta pregunta.", $usuarioId);
        }

        // Con un reporte alcanza: deja de salir en el juego hasta que la revise un editor.
        $this->database->execute(
            "UPDATE pregunta SET estado = 'cuarentena' WHERE id = ? AND estado = 'activa'",
            [$preguntaId]
        );

        return $this->respuesta(true, "¡Gracias! Un editor va a revisar la pregunta.", $usuarioId);
    }

    // ---------- Para el editor ----------

    // Preguntas en cuarentena con sus reportes pendientes y lo que ya se revisó antes.
    public function reportadas()
    {
        $preguntas = $this->database->queryAll(
            "SELECT p.id, p.texto, p.juego, p.dificultad, p.veces_mostrada, p.veces_acertada,
                    c.nombre AS categoria, c.fase, MIN(r.fecha) AS primer_reporte
             FROM pregunta p
             JOIN categoria c ON c.id = p.categoria_id
             JOIN reporte r ON r.pregunta_id = p.id AND r.estado = 'pendiente'
             WHERE p.estado = 'cuarentena'
             GROUP BY p.id
             ORDER BY primer_reporte"
        );

        foreach ($preguntas as &$pregunta) {
            $pregunta["respuestas"] = $this->database->queryAll(
                "SELECT texto, es_correcta FROM respuesta WHERE pregunta_id = ? ORDER BY id",
                [$pregunta["id"]]
            );
            $pregunta["reportes"] = $this->database->queryAll(
                "SELECT r.motivo, r.fecha, u.usuario FROM reporte r
                 JOIN usuario u ON u.id = r.reportado_por
                 WHERE r.pregunta_id = ? AND r.estado = 'pendiente' ORDER BY r.fecha",
                [$pregunta["id"]]
            );
            $pregunta["historial"] = $this->database->queryAll(
                "SELECT r.motivo, r.estado, r.fecha_revision, editor.usuario AS editor FROM reporte r
                 LEFT JOIN usuario editor ON editor.id = r.revisado_por
                 WHERE r.pregunta_id = ? AND r.estado <> 'pendiente' ORDER BY r.fecha_revision",
                [$pregunta["id"]]
            );
        }

        return $preguntas;
    }

    // $accion: "baja" (el reporte tenía razón: la pregunta queda inactiva),
    // "valida" (no tenía razón: vuelve al juego) o "corregida" (el editor la arregló y vuelve al juego).
    // Devuelve false si la pregunta ya no estaba en cuarentena (otro editor la resolvió antes).
    public function resolver($preguntaId, $accion, $editorId)
    {
        if ($accion === "baja") {
            $cambio = $this->database->execute(
                "UPDATE pregunta SET estado = 'inactiva', desactivada_por = ?, fecha_desactivacion = NOW()
                 WHERE id = ? AND estado = 'cuarentena'",
                [$editorId, $preguntaId]
            );
        } else {
            $cambio = $this->database->execute(
                "UPDATE pregunta SET estado = 'activa' WHERE id = ? AND estado = 'cuarentena'",
                [$preguntaId]
            );
        }

        if ($cambio === 0) {
            return false;
        }

        $estadoReporte = $accion === "valida" ? "rechazado" : "aprobado";
        $this->database->execute(
            "UPDATE reporte SET estado = ?, revisado_por = ?, fecha_revision = NOW()
             WHERE pregunta_id = ? AND estado = 'pendiente'",
            [$estadoReporte, $editorId, $preguntaId]
        );
        return true;
    }

    public function nombreDelMotivo($motivo)
    {
        return self::MOTIVOS[$motivo] ?? $motivo;
    }

    private function respuesta($ok, $mensaje, $usuarioId)
    {
        return ["ok" => $ok, "mensaje" => $mensaje, "restantes" => $this->restantes($usuarioId)];
    }
}
