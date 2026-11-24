<?php

namespace app\model;

use Throwable;

// "Quiero moderar": un jugador pide ser editor y un administrador lo aprueba o rechaza.
// Requisito: haber llegado a 4000 puntos (rango 4 de 7: Bronce en Shine o Portador de la llama
// en Die) en al menos 2 semanas distintas. Una semana de Shine y otra de Die también cuenta.
// Que sea difícil ser moderador.
class SolicitudEditorModel
{
    const PUNTOS_REQUERIDOS = 4000;
    const SEMANAS_REQUERIDAS = 2;

    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    // En cuántas semanas distintas llegó a los puntos requeridos (en Shine o en Die).
    // Cada semana (de lunes a domingo) y cada modo se cuenta por separado, como el ranking semanal.
    public function semanasCumplidas($usuarioId)
    {
        $partidas = $this->database->queryAll(
            "SELECT DATE(fecha_inicio) AS dia, modo, puntaje,
                    DATE_SUB(DATE(fecha_inicio), INTERVAL WEEKDAY(fecha_inicio) DAY) AS semana
             FROM partida WHERE usuario_id = ? ORDER BY fecha_inicio",
            [$usuarioId]
        );

        $porSemanaYModo = [];
        foreach ($partidas as $partida) {
            $porSemanaYModo[$partida["semana"]][$partida["modo"]][] = $partida;
        }

        $semanas = 0;
        foreach ($porSemanaYModo as $modos) {
            foreach ($modos as $partidasDelModo) {
                if (RankingModel::maximoConEnfriamiento($partidasDelModo) >= self::PUNTOS_REQUERIDOS) {
                    $semanas++;
                    break; // la semana ya cuenta: no hace falta mirar el otro modo
                }
            }
        }
        return $semanas;
    }

    public function cumpleRequisito($usuarioId)
    {
        return $this->semanasCumplidas($usuarioId) >= self::SEMANAS_REQUERIDAS;
    }

    // La última solicitud del jugador, o null si nunca pidió.
    public function ultima($usuarioId)
    {
        return $this->database->queryOne(
            "SELECT id, estado, fecha, fecha_revision,
                    fecha_revision >= " . PartidaModel::INICIO_SEMANA_SQL . " AS rechazada_esta_semana
             FROM solicitud_editor WHERE usuario_id = ? ORDER BY id DESC LIMIT 1",
            [$usuarioId]
        );
    }

    // Qué ve el jugador en su perfil:
    //   null         -> nada (no cumple, o ya es editor/admin)
    //   "puede"      -> el botón "Quiero moderar"
    //   "pendiente"  -> solicitud enviada, esperando al administrador
    //   "rechazada"  -> se la rechazaron esta semana; puede volver a pedir desde el lunes
    public function estadoParaElPerfil(array $usuario)
    {
        if ($usuario["rol"] !== "jugador") {
            return null;
        }

        $ultima = $this->ultima($usuario["id"]);
        if ($ultima !== null && $ultima["estado"] === "pendiente") {
            return "pendiente";
        }
        if ($ultima !== null && $ultima["estado"] === "rechazada" && $ultima["rechazada_esta_semana"]) {
            return "rechazada";
        }

        return $this->cumpleRequisito($usuario["id"]) ? "puede" : null;
    }

    // Crea la solicitud. Todo se vuelve a controlar acá, aunque alguien mande el POST a mano.
    public function pedir(array $usuario)
    {
        if ($this->estadoParaElPerfil($usuario) !== "puede") {
            return false;
        }

        $this->database->execute(
            "INSERT INTO solicitud_editor (usuario_id) VALUES (?)",
            [$usuario["id"]]
        );
        return true;
    }

    // El rol que tiene ahora en la base y si le queda una solicitud pendiente.
    // Lo usa el lobby, solo para quien tenía una pendiente (ver Sesion::revisarSolicitud).
    public function situacion($usuarioId)
    {
        $fila = $this->database->queryOne(
            "SELECT u.rol, EXISTS (SELECT 1 FROM solicitud_editor s
                                   WHERE s.usuario_id = u.id AND s.estado = 'pendiente') AS pendiente
             FROM usuario u WHERE u.id = ?",
            [$usuarioId]
        );
        return ["rol" => $fila["rol"], "pendiente" => (bool)$fila["pendiente"]];
    }

    // ---------- Para el administrador (AdminController, pestaña Solicitudes) ----------

    // Con lo que el administrador necesita para decidir: partidas jugadas y respuestas.
    public function pendientes()
    {
        return $this->database->queryAll(
            "SELECT s.id, s.fecha, u.id AS usuario_id, u.usuario, u.foto,
                    u.preguntas_respondidas, u.preguntas_acertadas,
                    (SELECT COUNT(*) FROM partida p WHERE p.usuario_id = u.id AND p.estado = 'terminada') AS partidas
             FROM solicitud_editor s JOIN usuario u ON u.id = s.usuario_id
             WHERE s.estado = 'pendiente' ORDER BY s.fecha"
        );
    }

    // Las últimas resueltas, la más reciente primero, con quién las resolvió.
    public function revisadas($cantidad = 10)
    {
        return $this->database->queryAll(
            "SELECT s.estado, s.fecha_revision, u.usuario, a.usuario AS admin
             FROM solicitud_editor s
             JOIN usuario u ON u.id = s.usuario_id
             LEFT JOIN usuario a ON a.id = s.revisada_por
             WHERE s.estado <> 'pendiente'
             ORDER BY s.fecha_revision DESC LIMIT " . (int)$cantidad
        );
    }

    // Aprobar: la solicitud queda aprobada y el jugador pasa a ser editor, las dos cosas juntas.
    // Devuelve false si ya no estaba pendiente.
    public function aprobar($solicitudId, $adminId)
    {
        $this->database->iniciarTransaccion();
        try {
            $cambio = $this->database->execute(
                "UPDATE solicitud_editor SET estado = 'aprobada', revisada_por = ?, fecha_revision = NOW()
                 WHERE id = ? AND estado = 'pendiente'",
                [$adminId, $solicitudId]
            );
            if ($cambio > 0) {
                $this->database->execute(
                    "UPDATE usuario SET rol = 'editor'
                     WHERE id = (SELECT usuario_id FROM solicitud_editor WHERE id = ?) AND rol = 'jugador'",
                    [$solicitudId]
                );
            }
            $this->database->confirmarTransaccion();
            return $cambio > 0;
        } catch (Throwable $e) {
            $this->database->deshacerTransaccion();
            throw $e;
        }
    }

    public function rechazar($solicitudId, $adminId)
    {
        return $this->database->execute(
            "UPDATE solicitud_editor SET estado = 'rechazada', revisada_por = ?, fecha_revision = NOW()
             WHERE id = ? AND estado = 'pendiente'",
            [$adminId, $solicitudId]
        ) > 0;
    }
}
