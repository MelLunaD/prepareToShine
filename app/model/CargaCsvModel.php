<?php

namespace app\model;

// Carga masiva de preguntas desde un CSV (panel del administrador, pestaña Preguntas).
// Se hace en dos pasos:
//   1. analizar(): lee el archivo y revisa cada fila con las mismas reglas del formulario
//      (PreguntaModel::validar). No guarda nada: es la vista previa.
//   2. cargar(): guarda las filas válidas. Antes las vuelve a validar, por si entre la vista
//      previa y el "Cargar" alguien creó una pregunta con el mismo texto.
// El CSV va separado por punto y coma (así lo guarda Excel en español) y sin id (es automático).
class CargaCsvModel
{
    const SEPARADOR = ";";
    const COLUMNAS = ["modo", "dificultad", "categoria", "juego", "pregunta", "correcta",
        "incorrecta_1", "incorrecta_2", "incorrecta_3"];
    const MAXIMO_FILAS = 500;
    const MAXIMO_BYTES = 1048576; // 1 MB

    // Para que "Fácil", "FACIL" o "difícil" también valgan.
    const DIFICULTADES = ["facil" => "facil", "fácil" => "facil", "media" => "media",
        "dificil" => "dificil", "difícil" => "dificil"];
    const NOMBRES_DIFICULTAD = ["facil" => "Fácil", "media" => "Media", "dificil" => "Difícil"];

    private $database;
    private $preguntaModel;

    public function __construct($database, $preguntaModel)
    {
        $this->database = $database;
        $this->preguntaModel = $preguntaModel;
    }

    // Lee el CSV subido y devuelve ["error" => null|"mensaje", "filas" => [...]].
    // Cada fila: ["numero" => 1, 2, 3... sin contar el encabezado, "datos" => formato del formulario,
    //             "texto", "categoria", "errores" => [...]].
    public function analizar($ruta, $bytes)
    {
        if ($bytes > self::MAXIMO_BYTES) {
            return $this->error("El archivo pesa más de 1 MB. Dividilo en partes más chicas.");
        }

        $contenido = $this->aUtf8((string)file_get_contents($ruta));
        $lineas = $this->leerFilas($contenido);
        if (count($lineas) === 0) {
            return $this->error("El archivo está vacío.");
        }

        $primera = array_shift($lineas);
        if (count($primera) === 1 && strpos($primera[0], ",") !== false) {
            return $this->error("Parece que el archivo está separado por comas. Tiene que ir separado por punto y coma (;): "
                . "en Excel, \"Guardar como\" > \"CSV (delimitado por punto y coma)\".");
        }
        $encabezado = array_map(fn($c) => $this->normalizar($c), $primera);
        $faltan = array_diff(self::COLUMNAS, $encabezado);
        if ($faltan) {
            return $this->error("Al encabezado le faltan columnas: " . implode(", ", $faltan)
                . ". Tiene que ser: " . implode(";", self::COLUMNAS) . " (separado por punto y coma).");
        }
        if (count($lineas) === 0) {
            return $this->error("El archivo solo tiene el encabezado: no hay preguntas para cargar.");
        }
        if (count($lineas) > self::MAXIMO_FILAS) {
            return $this->error("El archivo tiene " . count($lineas) . " preguntas y el máximo es "
                . self::MAXIMO_FILAS . " por vez. Dividilo en partes.");
        }

        $categorias = $this->categorias();
        $posicion = array_flip($encabezado);
        $vistas = [];
        $filas = [];

        foreach ($lineas as $i => $valores) {
            // +2: la fila 1 es el encabezado y las filas del archivo se cuentan desde 1.
            $fila = $this->analizarFila($i + 1, $valores, $posicion, $categorias);

            // Repetida dentro del mismo archivo (la base no la tiene todavía, así que validar() no la ve).
            $clave = mb_strtolower($fila["texto"]);
            if ($fila["texto"] !== "" && isset($vistas[$clave])) {
                $fila["errores"][] = "Está repetida en el archivo (igual que la fila " . $vistas[$clave] . ").";
            } elseif ($fila["texto"] !== "") {
                $vistas[$clave] = $fila["numero"];
            }
            $filas[] = $fila;
        }

        return ["error" => null, "filas" => $filas];
    }

    // Guarda las filas que no tienen errores. Cada pregunta se guarda con sus 4 respuestas en su
    // propia transacción (PreguntaModel::crear): si una falla, las demás no se pierden.
    // Devuelve ["cargadas" => [filas], "noCargadas" => [filas con sus errores]].
    public function cargar(array $filas, $adminId)
    {
        $cargadas = [];
        $noCargadas = [];

        foreach ($filas as $fila) {
            if (!$fila["errores"]) {
                // Se vuelve a validar contra la base (incluye las que se acaban de cargar).
                $fila["errores"] = array_map([$this, "mensajeParaCsv"], $this->preguntaModel->validar($fila["datos"]));
            }
            if ($fila["errores"]) {
                $noCargadas[] = $fila;
                continue;
            }
            $fila["id"] = $this->preguntaModel->crear($fila["datos"], $adminId);
            $cargadas[] = $fila;
        }

        return ["cargadas" => $cargadas, "noCargadas" => $noCargadas];
    }

    // Plantilla de ejemplo para "Bajar plantilla": el encabezado y una fila de cada modo.
    // Lleva BOM (los 3 bytes del principio) para que Excel la abra con los acentos bien.
    public function plantilla()
    {
        $filas = [
            self::COLUMNAS,
            ["shine", "facil", "Plataformas", "Super Mario", "¿Qué hermano de Mario viste de verde?", "Luigi", "Wario", "Toad", "Yoshi"],
            ["die", "dificil", "Jefes", "Dark Souls", "¿Qué jefe cuida la campana de la Parroquia de los No Muertos?", "Las Gárgolas Campaneras", "El Demonio Tauro", "El Demonio Capra", "Ornstein"],
        ];
        return "\xEF\xBB\xBF" . $this->aCsv($filas);
    }

    // Las filas que no se cargaron, con su error al final, para corregirlas y volver a subirlas.
    public function csvDeErrores(array $filas)
    {
        $lineas = [array_merge(self::COLUMNAS, ["error"])];
        foreach ($filas as $fila) {
            $lineas[] = array_merge($fila["original"], [implode(" ", $fila["errores"])]);
        }
        return "\xEF\xBB\xBF" . $this->aCsv($lineas);
    }

    // ---------- Privados ----------

    private function analizarFila($numero, array $valores, array $posicion, array $categorias)
    {
        $valor = fn($columna) => trim((string)($valores[$posicion[$columna]] ?? ""));
        $original = array_map($valor, self::COLUMNAS);

        $errores = [];
        $modo = mb_strtolower($valor("modo"));
        if (!isset($categorias[$modo])) {
            $errores[] = "El modo tiene que ser shine o die.";
        }

        $categoriaId = 0;
        $nombreCategoria = $valor("categoria");
        if (isset($categorias[$modo])) {
            $categoriaId = $categorias[$modo][$this->normalizar($nombreCategoria)] ?? 0;
            if ($categoriaId !== 0) {
                // Con el nombre bien escrito, aunque en el archivo venga en minúsculas o sin tilde.
                $nombreCategoria = array_search($categoriaId, $categorias[$modo . "_nombres"], true);
            } else {
                $errores[] = "La categoría \"$nombreCategoria\" no existe en " . ucfirst($modo)
                    . ". Puede ser: " . implode(", ", array_keys($categorias[$modo . "_nombres"])) . ".";
            }
        }

        $dificultad = self::DIFICULTADES[mb_strtolower($valor("dificultad"))] ?? "";
        if ($dificultad === "") {
            $errores[] = "La dificultad tiene que ser facil, media o dificil.";
        }

        // La correcta va primera; en el juego las respuestas se muestran mezcladas.
        $datos = [
            "categoria_id" => $categoriaId,
            "juego" => $valor("juego"),
            "texto" => $valor("pregunta"),
            "respuestas" => [$valor("correcta"), $valor("incorrecta_1"), $valor("incorrecta_2"), $valor("incorrecta_3")],
            "correcta" => 0,
            "dificultad" => $dificultad,
        ];

        // Las demás reglas son las del formulario. Se saltean las que ya avisamos arriba con un
        // mensaje más claro para un CSV (categoría y dificultad).
        $mensajesDelFormulario = array_filter(
            $this->preguntaModel->validar($datos, 0, $dificultad !== ""),
            fn($e) => $e !== "Elegí una categoría."
        );
        $errores = array_merge($errores, array_map([$this, "mensajeParaCsv"], array_values($mensajesDelFormulario)));

        return [
            "numero" => $numero,
            "datos" => $datos,
            "original" => $original,
            "texto" => $datos["texto"],
            // Si el modo está mal se muestra tal como vino (no se asume Shine).
            "categoria" => (["shine" => "Shine", "die" => "Die"][$modo]
                    ?? ($valor("modo") === "" ? "Sin modo" : "Modo \"" . $valor("modo") . "\""))
                . " · " . ($nombreCategoria === "" ? "sin categoría" : $nombreCategoria),
            // Para mostrar la fila completa en la vista previa (tal como vino en el archivo).
            "dificultadTexto" => self::NOMBRES_DIFICULTAD[$dificultad] ?? $valor("dificultad"),
            "juego" => $valor("juego"),
            "respuestasCsv" => [
                ["respuesta" => $valor("correcta"), "esCorrecta" => true],
                ["respuesta" => $valor("incorrecta_1"), "esCorrecta" => false],
                ["respuesta" => $valor("incorrecta_2"), "esCorrecta" => false],
                ["respuesta" => $valor("incorrecta_3"), "esCorrecta" => false],
            ],
            "errores" => $errores,
        ];
    }

    // En el CSV no hay letras A-D ni botón de "correcta": se nombran las columnas.
    private function mensajeParaCsv($mensaje)
    {
        return strtr($mensaje, [
            "Falta completar la respuesta A." => "Falta la respuesta correcta.",
            "Falta completar la respuesta B." => "Falta incorrecta_1.",
            "Falta completar la respuesta C." => "Falta incorrecta_2.",
            "Falta completar la respuesta D." => "Falta incorrecta_3.",
            "La respuesta A" => "La respuesta correcta",
            "La respuesta B" => "incorrecta_1",
            "La respuesta C" => "incorrecta_2",
            "La respuesta D" => "incorrecta_3",
        ]);
    }

    // ["shine" => ["plataformas" => 1, ...], "shine_nombres" => ["Plataformas" => 1, ...], "die" => ...]
    private function categorias()
    {
        $categorias = ["shine" => [], "die" => [], "shine_nombres" => [], "die_nombres" => []];
        foreach ($this->database->queryAll("SELECT id, nombre, fase FROM categoria ORDER BY id") as $c) {
            $categorias[$c["fase"]][$this->normalizar($c["nombre"])] = (int)$c["id"];
            $categorias[$c["fase"] . "_nombres"][$c["nombre"]] = (int)$c["id"];
        }
        return $categorias;
    }

    // Excel en Windows guarda los CSV en "ANSI" (Windows-1252): se pasa a UTF-8 para que
    // los acentos y las eñes no se rompan. Si ya viene en UTF-8, se le saca el BOM.
    private function aUtf8($contenido)
    {
        if (strncmp($contenido, "\xEF\xBB\xBF", 3) === 0) {
            return substr($contenido, 3);
        }
        return mb_check_encoding($contenido, "UTF-8") ? $contenido : mb_convert_encoding($contenido, "UTF-8", "Windows-1252");
    }

    // str_getcsv respeta las comillas: una pregunta puede tener ";" adentro si va entre comillas.
    // Las filas vacías se ignoran.
    private function leerFilas($contenido)
    {
        $archivo = fopen("php://memory", "r+");
        fwrite($archivo, $contenido);
        rewind($archivo);

        $filas = [];
        while (($valores = fgetcsv($archivo, 0, self::SEPARADOR, '"', "")) !== false) {
            if (count(array_filter($valores, fn($v) => trim((string)$v) !== "")) > 0) {
                $filas[] = $valores;
            }
        }
        fclose($archivo);
        return $filas;
    }

    private function aCsv(array $filas)
    {
        $archivo = fopen("php://memory", "r+");
        foreach ($filas as $fila) {
            fputcsv($archivo, $fila, self::SEPARADOR, '"', "");
        }
        rewind($archivo);
        $csv = stream_get_contents($archivo);
        fclose($archivo);
        return $csv;
    }

    // Minúsculas y sin tildes: "Acción y Aventura" y "accion y aventura" son la misma categoría.
    private function normalizar($texto)
    {
        return strtr(mb_strtolower(trim((string)$texto)), ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n"]);
    }

    private function error($mensaje)
    {
        return ["error" => $mensaje, "filas" => []];
    }
}
