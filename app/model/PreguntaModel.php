<?php

namespace app\model;

use Throwable;

// Preguntas vistas desde la moderación: el editor crea preguntas nuevas y revisa las
// reportadas (estado 'cuarentena') y las sugeridas (estado 'revision').
// El editor NO ve el listado de todas las preguntas: solo toca las que le llegan.
class PreguntaModel
{
    const DIFICULTADES = ["facil" => "Fácil", "media" => "Media", "dificil" => "Difícil"];
    const CANTIDAD_RESPUESTAS = 4;
    const LETRAS = ["A", "B", "C", "D"];

    // Cada editor puede crear hasta 15 preguntas por semana (de lunes a domingo).
    const CREADAS_POR_SEMANA = 15;

    const LARGO_MINIMO_TEXTO = 10;
    const LARGO_TEXTO = 255;      // pregunta.texto
    const LARGO_JUEGO = 40;       // pregunta.juego
    const LARGO_RESPUESTA = 150;  // respuesta.texto

    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    // Categorías separadas por modo, para el <select> del formulario.
    public function categoriasPorModo()
    {
        $categorias = $this->database->queryAll("SELECT id, nombre, fase FROM categoria ORDER BY fase, id");

        $porModo = ["shine" => [], "die" => []];
        foreach ($categorias as $categoria) {
            $porModo[$categoria["fase"]][] = ["id" => (int)$categoria["id"], "nombre" => $categoria["nombre"]];
        }
        return $porModo;
    }

    // Cuántas esperan al editor: reportadas (en cuarentena) y sugeridas (en revisión).
    public function pendientes()
    {
        $filas = $this->database->queryAll(
            "SELECT estado, COUNT(*) AS cantidad FROM pregunta
             WHERE estado IN ('cuarentena', 'revision') GROUP BY estado"
        );

        $pendientes = ["reportadas" => 0, "sugeridas" => 0];
        foreach ($filas as $fila) {
            $clave = $fila["estado"] === "cuarentena" ? "reportadas" : "sugeridas";
            $pendientes[$clave] = (int)$fila["cantidad"];
        }
        return $pendientes;
    }

    // Cuántas preguntas más puede crear el editor esta semana.
    public function creacionesRestantes($editorId)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS cantidad FROM pregunta
             WHERE creada_por = ? AND fecha_alta >= " . PartidaModel::INICIO_SEMANA_SQL,
            [$editorId]
        );
        return max(0, self::CREADAS_POR_SEMANA - (int)$fila["cantidad"]);
    }

    // Limpia lo que llega del formulario. Siempre devuelve las 4 respuestas, aunque vengan vacías,
    // para poder volver a mostrar el formulario con lo que el editor ya había escrito.
    public function datosDelFormulario(array $post)
    {
        $respuestas = $post["respuestas"] ?? [];
        $datos = [
            "categoria_id" => (int)($post["categoria_id"] ?? 0),
            "juego" => trim((string)($post["juego"] ?? "")),
            "texto" => trim((string)($post["texto"] ?? "")),
            "respuestas" => [],
            "correcta" => isset($post["correcta"]) && $post["correcta"] !== "" ? (int)$post["correcta"] : null,
            "dificultad" => (string)($post["dificultad"] ?? ""),
        ];

        for ($i = 0; $i < self::CANTIDAD_RESPUESTAS; $i++) {
            $datos["respuestas"][] = trim((string)($respuestas[$i] ?? ""));
        }
        return $datos;
    }

    // Devuelve la lista de errores (vacía si está todo bien).
    // $excluirId: al editar, la propia pregunta no cuenta como "texto repetido".
    public function validar(array $datos, $excluirId = 0)
    {
        $errores = [];

        $categoria = $this->database->queryOne("SELECT id FROM categoria WHERE id = ?", [$datos["categoria_id"]]);
        if ($categoria === null) {
            $errores[] = "Elegí una categoría.";
        }

        if (mb_strlen($datos["texto"]) < self::LARGO_MINIMO_TEXTO) {
            $errores[] = "La pregunta tiene que tener al menos " . self::LARGO_MINIMO_TEXTO . " caracteres.";
        } elseif (mb_strlen($datos["texto"]) > self::LARGO_TEXTO) {
            $errores[] = "La pregunta no puede pasar los " . self::LARGO_TEXTO . " caracteres.";
        } elseif ($this->existeTexto($datos["texto"], $excluirId)) {
            $errores[] = "Ya existe una pregunta con ese mismo texto.";
        }

        if (mb_strlen($datos["juego"]) > self::LARGO_JUEGO) {
            $errores[] = "El juego no puede pasar los " . self::LARGO_JUEGO . " caracteres.";
        }

        foreach ($datos["respuestas"] as $i => $respuesta) {
            if ($respuesta === "") {
                $errores[] = "Falta completar la respuesta " . self::LETRAS[$i] . ".";
            } elseif (mb_strlen($respuesta) > self::LARGO_RESPUESTA) {
                $errores[] = "La respuesta " . self::LETRAS[$i] . " no puede pasar los " . self::LARGO_RESPUESTA . " caracteres.";
            }
        }

        $completas = array_filter($datos["respuestas"], fn($r) => $r !== "");
        $distintas = array_unique(array_map("mb_strtolower", $completas));
        if (count($distintas) < count($completas)) {
            $errores[] = "Hay respuestas repetidas: las 4 tienen que ser distintas.";
        }

        if ($datos["correcta"] === null || $datos["correcta"] < 0 || $datos["correcta"] >= self::CANTIDAD_RESPUESTAS) {
            $errores[] = "Marcá cuál es la respuesta correcta.";
        }

        if (!isset(self::DIFICULTADES[$datos["dificultad"]])) {
            $errores[] = "Elegí la dificultad inicial.";
        }

        return $errores;
    }

    // La pregunta que crea un editor entra activa: la aprueba él mismo.
    // La dificultad que elige es solo la inicial; después de 10 respuestas el juego
    // la calcula con el % de aciertos (PartidaModel::DIFICULTAD_SQL).
    public function crear(array $datos, $editorId)
    {
        $this->database->iniciarTransaccion();
        try {
            $this->database->execute(
                "INSERT INTO pregunta (categoria_id, juego, texto, estado, dificultad, creada_por, aprobada_por, fecha_aprobacion)
                 VALUES (?, ?, ?, 'activa', ?, ?, ?, NOW())",
                [$datos["categoria_id"], $datos["juego"] === "" ? null : $datos["juego"], $datos["texto"],
                 $datos["dificultad"], $editorId, $editorId]
            );
            $preguntaId = (int)$this->database->lastInsertId();

            foreach ($datos["respuestas"] as $i => $respuesta) {
                $this->database->execute(
                    "INSERT INTO respuesta (pregunta_id, texto, es_correcta) VALUES (?, ?, ?)",
                    [$preguntaId, $respuesta, $i === $datos["correcta"] ? 1 : 0]
                );
            }

            $this->database->confirmarTransaccion();
            return $preguntaId;
        } catch (Throwable $e) {
            $this->database->deshacerTransaccion();
            throw $e;
        }
    }

    // La comparación usa la collation de la tabla: no distingue mayúsculas ni tildes.
    private function existeTexto($texto, $excluirId = 0)
    {
        return $this->database->queryOne(
            "SELECT id FROM pregunta WHERE texto = ? AND id <> ? LIMIT 1",
            [$texto, $excluirId]
        ) !== null;
    }

    // Los datos de una pregunta en el formato del formulario, solo si el editor puede tocarla:
    // reportada (cuarentena) o sugerida (revision). Las demás no las edita (permisos limitados).
    public function paraEditar($id)
    {
        $pregunta = $this->database->queryOne(
            "SELECT id, categoria_id, juego, texto, dificultad, estado FROM pregunta
             WHERE id = ? AND estado IN ('cuarentena', 'revision')",
            [$id]
        );
        if ($pregunta === null) {
            return null;
        }

        $respuestas = $this->database->queryAll(
            "SELECT texto, es_correcta FROM respuesta WHERE pregunta_id = ? ORDER BY id",
            [$id]
        );

        $datos = [
            "id" => (int)$pregunta["id"],
            "estado" => $pregunta["estado"],
            "categoria_id" => (int)$pregunta["categoria_id"],
            "juego" => (string)$pregunta["juego"],
            "texto" => $pregunta["texto"],
            "respuestas" => array_column($respuestas, "texto"),
            "correcta" => null,
            "dificultad" => $pregunta["dificultad"],
        ];
        foreach ($respuestas as $i => $respuesta) {
            if ((int)$respuesta["es_correcta"] === 1) {
                $datos["correcta"] = $i;
            }
        }
        return $datos;
    }

    // Guarda los cambios del editor. Las respuestas se actualizan en el lugar (mismos id),
    // porque partida_pregunta guarda qué respuesta eligió cada jugador.
    public function actualizar($id, array $datos, $editorId)
    {
        $this->database->iniciarTransaccion();
        try {
            $this->database->execute(
                "UPDATE pregunta SET categoria_id = ?, juego = ?, texto = ?, dificultad = ?,
                        modificada_por = ?, fecha_modificacion = NOW()
                 WHERE id = ?",
                [$datos["categoria_id"], $datos["juego"] === "" ? null : $datos["juego"], $datos["texto"],
                 $datos["dificultad"], $editorId, $id]
            );

            $ids = array_column(
                $this->database->queryAll("SELECT id FROM respuesta WHERE pregunta_id = ? ORDER BY id", [$id]),
                "id"
            );
            foreach ($ids as $i => $respuestaId) {
                $this->database->execute(
                    "UPDATE respuesta SET texto = ?, es_correcta = ? WHERE id = ?",
                    [$datos["respuestas"][$i], $i === $datos["correcta"] ? 1 : 0, $respuestaId]
                );
            }

            $this->database->confirmarTransaccion();
        } catch (Throwable $e) {
            $this->database->deshacerTransaccion();
            throw $e;
        }
    }
}
