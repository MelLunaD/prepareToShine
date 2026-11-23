<?php

namespace app\model;

use Throwable;

// Preguntas vistas desde la moderación: el editor crea preguntas nuevas y revisa las
// reportadas (estado 'cuarentena') y las sugeridas (estado 'revision').
// El editor NO ve el listado de todas las preguntas: solo toca las que le llegan
// (y las puede buscar, solo para mirar, al revisar una sugerida).
// El jugador sugiere preguntas: quedan en 'revision' hasta que un editor las apruebe.
class PreguntaModel
{
    const DIFICULTADES = ["facil" => "Fácil", "media" => "Media", "dificil" => "Difícil"];
    const CANTIDAD_RESPUESTAS = 4;
    const LETRAS = ["A", "B", "C", "D"];

    // Cada editor puede crear hasta 15 preguntas por semana (de lunes a domingo).
    const CREADAS_POR_SEMANA = 15;

    // Cada jugador puede sugerir hasta 3 preguntas por semana (como los reportes).
    const SUGERIDAS_POR_SEMANA = 3;

    // Buscador de parecidas: máximo de resultados y palabras que no sirven para buscar.
    const RESULTADOS_BUSQUEDA = 20;
    const LARGO_MINIMO_BUSQUEDA = 3;
    const PALABRAS_VACIAS = ["que", "cual", "cuales", "del", "los", "las", "por", "con", "para", "una", "uno",
        "unos", "unas", "este", "esta", "estos", "estas", "ese", "esa", "como", "cuando", "donde", "quien",
        "cuanto", "cuanta", "cuantos", "cuantas", "sus", "son", "fue", "era", "hay", "mas", "sin", "entre",
        "sobre", "desde", "the", "and"];

    const NOMBRE_ESTADO = ["activa" => "Activa", "inactiva" => "Dada de baja", "cuarentena" => "Reportada", "revision" => "Sugerida"];

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

    // Cuántas preguntas más puede crear el editor esta semana. Cuentan las que creó y aprobó
    // él mismo (las que sugirió como jugador antes de ser editor no).
    public function creacionesRestantes($editorId)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS cantidad FROM pregunta
             WHERE creada_por = ? AND aprobada_por = creada_por AND fecha_alta >= " . PartidaModel::INICIO_SEMANA_SQL,
            [$editorId]
        );
        return max(0, self::CREADAS_POR_SEMANA - (int)$fila["cantidad"]);
    }

    // Cuántas preguntas más puede sugerir el jugador esta semana.
    public function sugerenciasRestantes($usuarioId)
    {
        $fila = $this->database->queryOne(
            "SELECT COUNT(*) AS cantidad FROM pregunta
             WHERE creada_por = ? AND (aprobada_por IS NULL OR aprobada_por <> creada_por)
               AND fecha_alta >= " . PartidaModel::INICIO_SEMANA_SQL,
            [$usuarioId]
        );
        return max(0, self::SUGERIDAS_POR_SEMANA - (int)$fila["cantidad"]);
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
    // $pideDificultad: false para la sugerencia del jugador (la dificultad la pone el editor al aprobar).
    public function validar(array $datos, $excluirId = 0, $pideDificultad = true)
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

        if ($pideDificultad && !isset(self::DIFICULTADES[$datos["dificultad"]])) {
            $errores[] = "Elegí la dificultad inicial.";
        }

        return $errores;
    }

    // La pregunta que crea un editor entra activa: la aprueba él mismo.
    // La dificultad que elige es solo la inicial; después de 10 respuestas el juego
    // la calcula con el % de aciertos (PartidaModel::DIFICULTAD_SQL).
    public function crear(array $datos, $editorId)
    {
        return $this->insertar($datos, $editorId, true);
    }

    // La que sugiere un jugador entra en 'revision': no sale en el juego hasta que la apruebe un editor.
    public function sugerir(array $datos, $usuarioId)
    {
        return $this->insertar($datos, $usuarioId, false);
    }

    private function insertar(array $datos, $usuarioId, $activa)
    {
        $this->database->iniciarTransaccion();
        try {
            $this->database->execute(
                "INSERT INTO pregunta (categoria_id, juego, texto, estado, dificultad, creada_por, aprobada_por, fecha_aprobacion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, " . ($activa ? "NOW()" : "NULL") . ")",
                [$datos["categoria_id"], $datos["juego"] === "" ? null : $datos["juego"], $datos["texto"],
                 $activa ? "activa" : "revision", $datos["dificultad"] ?: "media", $usuarioId, $activa ? $usuarioId : null]
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

    // ---------- Sugeridas (para el editor) ----------

    // Las que esperan revisión, la más vieja primero, con sus respuestas y quién la sugirió.
    public function sugeridas()
    {
        $preguntas = $this->database->queryAll(
            "SELECT p.id, p.texto, p.juego, p.fecha_alta, c.nombre AS categoria, c.fase, u.usuario AS sugerida_por
             FROM pregunta p
             JOIN categoria c ON c.id = p.categoria_id
             JOIN usuario u ON u.id = p.creada_por
             WHERE p.estado = 'revision'
             ORDER BY p.fecha_alta, p.id
             LIMIT 20"
        );

        foreach ($preguntas as &$pregunta) {
            $pregunta["respuestas"] = $this->database->queryAll(
                "SELECT texto, es_correcta FROM respuesta WHERE pregunta_id = ? ORDER BY id",
                [$pregunta["id"]]
            );
        }
        return $preguntas;
    }

    // Aprobar: pasa a activa con la dificultad inicial que elige el editor.
    // Devuelve false si ya no estaba en revisión (otro editor la resolvió antes).
    public function aprobar($id, $dificultad, $editorId)
    {
        return $this->database->execute(
            "UPDATE pregunta SET estado = 'activa', dificultad = ?, aprobada_por = ?, fecha_aprobacion = NOW()
             WHERE id = ? AND estado = 'revision'",
            [$dificultad, $editorId, $id]
        ) > 0;
    }

    // Rechazar: la sugerida se borra (sus respuestas se van con ON DELETE CASCADE).
    // Nunca se jugó, así que no la referencia ninguna partida.
    public function rechazar($id)
    {
        return $this->database->execute(
            "DELETE FROM pregunta WHERE id = ? AND estado = 'revision'",
            [$id]
        ) > 0;
    }

    // Buscador de parecidas (solo lectura): trae las preguntas que tienen alguna de las palabras
    // y las ordena primero por cuántas palabras tienen y después por similar_text().
    // $excluirId: la sugerida que se está revisando no se busca a sí misma.
    // $fase: 'shine' o 'die', para buscar solo entre las preguntas del mismo modo (null = todas).
    public function buscar($texto, $excluirId = 0, $fase = null)
    {
        $palabras = $this->palabrasParaBuscar($texto);
        if (!$palabras) {
            return [];
        }

        // Un LIKE por palabra. La collation de la tabla no distingue mayúsculas ni tildes.
        $condiciones = implode(" OR ", array_fill(0, count($palabras), "p.texto LIKE ?"));
        $filtroFase = $fase === null ? "" : " AND c.fase = ?";
        $parametros = array_merge([(int)$excluirId], $fase === null ? [] : [$fase], array_map(fn($p) => "%" . $p . "%", $palabras));
        $candidatas = $this->database->queryAll(
            "SELECT p.id, p.texto, p.estado, p.juego, c.nombre AS categoria, c.fase
             FROM pregunta p JOIN categoria c ON c.id = p.categoria_id
             WHERE p.id <> ?$filtroFase AND ($condiciones)
             LIMIT 300",
            $parametros
        );

        $buscado = $this->normalizar($texto);
        foreach ($candidatas as &$candidata) {
            $normalizada = $this->normalizar($candidata["texto"]);
            $candidata["coincidencias"] = count(array_filter($palabras, fn($p) => strpos($normalizada, $p) !== false));
            similar_text($buscado, $normalizada, $porcentaje);
            $candidata["parecido"] = (int)round($porcentaje);
        }
        unset($candidata);

        usort($candidatas, fn($a, $b) => [$b["coincidencias"], $b["parecido"]] <=> [$a["coincidencias"], $a["parecido"]]);

        return array_map(fn($c) => [
            "id" => (int)$c["id"],
            "texto" => $c["texto"],
            "categoria" => $c["juego"] ? $c["categoria"] . " (" . $c["juego"] . ")" : $c["categoria"],
            "modo" => $c["fase"] === "die" ? "Die" : "Shine",
            "estado" => $c["estado"],
            "estadoNombre" => self::NOMBRE_ESTADO[$c["estado"]] ?? $c["estado"],
            "coincidencias" => $c["coincidencias"],
            "palabras" => count($palabras),
            "parecido" => $c["parecido"],
        ], array_slice($candidatas, 0, self::RESULTADOS_BUSQUEDA));
    }

    // "¿Quién es el jefe final de Dark Souls?" -> ["quien" no (vacía), "jefe", "final", "dark", "souls"]
    private function palabrasParaBuscar($texto)
    {
        $palabras = preg_split('/[^\p{L}\p{N}]+/u', $this->normalizar($texto), -1, PREG_SPLIT_NO_EMPTY);
        $palabras = array_filter($palabras, fn($p) => mb_strlen($p) >= self::LARGO_MINIMO_BUSQUEDA
            && !in_array($p, self::PALABRAS_VACIAS, true));
        return array_slice(array_values(array_unique($palabras)), 0, 8);
    }

    // Minúsculas y sin tildes, para comparar como lo hace la base.
    private function normalizar($texto)
    {
        return strtr(mb_strtolower(trim($texto)), ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n"]);
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
    // A la sugerida no se le carga dificultad: la elige el editor al aprobarla.
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
            "dificultad" => $pregunta["estado"] === "revision" ? "" : $pregunta["dificultad"],
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
