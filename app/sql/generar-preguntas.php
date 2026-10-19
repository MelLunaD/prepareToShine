<?php
// Genera 03-preguntas.sql a partir de preguntas.csv.
// Uso (desde la raíz del repo): php app/sql/generar-preguntas.php
//
// Columnas del CSV: fase, dificultad, categoria, juego, pregunta, correcta, incorrecta_1, incorrecta_2, incorrecta_3

const CATEGORIAS = [
    'shine' => [
        'Plataformas' => 1,
        'Acción y aventura' => 2,
        'Shooters' => 3,
        'RPG' => 4,
        'Lucha' => 5,
        'Deportes y carreras' => 6,
    ],
    'die' => [
        'Jefes' => 7,
        'Lore' => 8,
        'Frases' => 9,
        'Skills' => 10,
    ],
];
const DIFICULTADES = ['facil', 'media', 'dificil'];
const USUARIO_SISTEMA = 1;
const FILAS_POR_INSERT = 100;

function texto($valor)
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $valor) . "'";
}

$csv = fopen(__DIR__ . '/preguntas.csv', 'r');
$encabezado = fgetcsv($csv, null, ',', '"', '');

$preguntas = [];
$respuestas = [];
$linea = 1;
// Semilla fija: el orden de las opciones queda mezclado pero el archivo sale igual cada vez.
mt_srand(2026);

while (($fila = fgetcsv($csv, null, ',', '"', '')) !== false) {
    $linea++;
    if (count($fila) !== 9) {
        fwrite(STDERR, "Línea $linea: tiene " . count($fila) . " columnas en vez de 9\n");
        exit(1);
    }
    [$fase, $dificultad, $categoria, $juego, $texto, $correcta, $i1, $i2, $i3] = array_map('trim', $fila);

    if (!isset(CATEGORIAS[$fase][$categoria]) || !in_array($dificultad, DIFICULTADES, true)) {
        fwrite(STDERR, "Línea $linea: fase, categoría o dificultad desconocida ($fase, $categoria, $dificultad)\n");
        exit(1);
    }

    $id = count($preguntas) + 1;
    $preguntas[] = sprintf(
        "(%d, %d, %s, %s, 'activa', '%s', %d, %d, NOW())",
        $id,
        CATEGORIAS[$fase][$categoria],
        $juego === '' ? 'NULL' : texto($juego),
        texto($texto),
        $dificultad,
        USUARIO_SISTEMA,
        USUARIO_SISTEMA
    );

    $opciones = [[$correcta, 1], [$i1, 0], [$i2, 0], [$i3, 0]];
    shuffle($opciones);
    foreach ($opciones as [$opcion, $esCorrecta]) {
        $respuestas[] = sprintf("(%d, %s, %d)", $id, texto($opcion), $esCorrecta);
    }
}
fclose($csv);

$sql = "-- Prepare to Shine / Prepare to Die: preguntas iniciales (" . count($preguntas) . " preguntas, 4 opciones cada una).\n"
    . "-- Archivo generado con: php app/sql/generar-preguntas.php (no editar a mano, editar preguntas.csv).\n"
    . "-- Importar después de 02-datos-iniciales.sql.\n\n"
    . "SET NAMES utf8mb4;\n\n";

foreach (array_chunk($preguntas, FILAS_POR_INSERT) as $bloque) {
    $sql .= "INSERT INTO pregunta (id, categoria_id, juego, texto, estado, dificultad, creada_por, aprobada_por, fecha_aprobacion) VALUES\n"
        . implode(",\n", $bloque) . ";\n\n";
}
foreach (array_chunk($respuestas, FILAS_POR_INSERT * 4) as $bloque) {
    $sql .= "INSERT INTO respuesta (pregunta_id, texto, es_correcta) VALUES\n"
        . implode(",\n", $bloque) . ";\n\n";
}

file_put_contents(__DIR__ . '/03-preguntas.sql', $sql);
echo "03-preguntas.sql: " . count($preguntas) . " preguntas y " . count($respuestas) . " respuestas\n";
