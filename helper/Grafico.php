<?php

// Encapsula CpChart (vendor/cpchart, port de pChart, sin Composer): dibuja los gráficos del PDF
// como imágenes PNG con la extensión GD de PHP. En pantalla los gráficos son de Chart.js; acá
// se dibujan en el servidor porque Dompdf no ejecuta JavaScript.
// Devuelve cada imagen como "data:image/png;base64,...", lista para un <img src> del PDF.

require_once __DIR__ . "/../vendor/cpchart/constants.php";

spl_autoload_register(function ($clase) {
    if (strpos($clase, "CpChart\\") === 0) {
        require_once __DIR__ . "/../vendor/cpchart/src/" . str_replace("\\", "/", substr($clase, 8)) . ".php";
    }
});

use CpChart\Chart\Pie;
use CpChart\Data;
use CpChart\Image;

class Grafico
{
    const FUENTE = __DIR__ . "/../vendor/cpchart/resources/fonts/verdana.ttf";
    const TEXTO = ["R" => 60, "G" => 64, "B" => 72];
    const GRILLA = ["R" => 220, "G" => 222, "B" => 226];

    // Barras paradas (vertical) o acostadas (horizontal, para nombres largos como los países).
    // $color: [R, G, B]. $ejeEtiquetas y $ejeValores: nombres de los ejes (null = sin nombre).
    public static function barras(array $etiquetas, array $valores, array $color, $ancho = 700, $alto = 260,
                                  $horizontal = false, $ejeEtiquetas = null, $ejeValores = null)
    {
        $data = new Data();
        $data->addPoints($valores ?: [0], "Cantidad");
        $data->addPoints($etiquetas ?: [""], "Etiquetas");
        $data->setAbscissa("Etiquetas");
        $data->setPalette("Cantidad", ["R" => $color[0], "G" => $color[1], "B" => $color[2]]);
        $imagen = self::imagen($ancho, $alto, $data);
        // Márgenes: lugar para los números de la escala y para los nombres de los ejes.
        $arriba = $horizontal ? 45 : 15;
        $izquierda = $horizontal ? 130 : ($ejeValores !== null ? 70 : 45);
        $abajo = $horizontal ? 10 : ($ejeEtiquetas !== null ? 55 : 35);
        $derecha = $ancho - 20;
        $imagen->setGraphArea($izquierda, $arriba, $derecha, $alto - $abajo);

        $imagen->drawScale([
            "Pos" => $horizontal ? SCALE_POS_TOPBOTTOM : SCALE_POS_LEFTRIGHT,
            // Desde 0 y con números enteros: no hay "media partida".
            "Mode" => SCALE_MODE_MANUAL,
            "ManualScale" => [0 => ["Min" => 0, "Max" => self::maximo($valores)]],
            "Factors" => [1, 2, 5, 10, 20, 50, 100],
            "GridR" => self::GRILLA["R"], "GridG" => self::GRILLA["G"], "GridB" => self::GRILLA["B"],
            "AxisR" => 180, "AxisG" => 180, "AxisB" => 180,
            "TickR" => 180, "TickG" => 180, "TickB" => 180,
            "DrawSubTicks" => false,
            "CycleBackground" => false,
            "LabelSkip" => count($etiquetas) > 16 ? 1 : 0,
        ]);

        $imagen->drawBarChart([
            "DisplayValues" => count($etiquetas) <= 16,
            "DisplayColor" => DISPLAY_MANUAL,
            "DisplayR" => self::TEXTO["R"], "DisplayG" => self::TEXTO["G"], "DisplayB" => self::TEXTO["B"],
            "Interleave" => 0.6,
        ]);

        // Nombres de los ejes, en negrita aparte para que se lea qué es cada cosa.
        $centroX = (int)(($izquierda + $derecha) / 2);
        $texto = ["R" => self::TEXTO["R"], "G" => self::TEXTO["G"], "B" => self::TEXTO["B"], "FontSize" => 10];
        if ($horizontal) {
            // Acostado: los valores van arriba y las etiquetas a la izquierda.
            if ($ejeValores !== null) {
                $imagen->drawText($centroX, 12, $ejeValores, $texto + ["Align" => TEXT_ALIGN_TOPMIDDLE]);
            }
        } else {
            if ($ejeEtiquetas !== null) {
                $imagen->drawText($centroX, $alto - 8, $ejeEtiquetas, $texto + ["Align" => TEXT_ALIGN_BOTTOMMIDDLE]);
            }
            if ($ejeValores !== null) {
                $centroY = (int)(($arriba + $alto - $abajo) / 2);
                $imagen->drawText(16, $centroY, $ejeValores, $texto + ["Align" => TEXT_ALIGN_MIDDLEMIDDLE, "Angle" => 90]);
            }
        }

        return self::comoDataUri($imagen);
    }

    // Torta con agujero (anillo) y su leyenda al costado. $colores: un [R, G, B] por porción.
    public static function anillo(array $etiquetas, array $valores, array $colores, $ancho = 420, $alto = 220)
    {
        $data = new Data();
        // Una torta sin datos no se puede dibujar: se muestra un anillo gris vacío.
        $vacio = array_sum($valores) === 0;
        $data->addPoints($vacio ? [1] : $valores, "Cantidad");
        $data->addPoints($vacio ? ["Sin datos"] : $etiquetas, "Etiquetas");
        $data->setAbscissa("Etiquetas");

        $imagen = self::imagen($ancho, $alto, $data);
        $pie = new Pie($imagen, $data);
        foreach ($vacio ? [[200, 200, 200]] : $colores as $i => $color) {
            $data->Palette[$i] = ["R" => $color[0], "G" => $color[1], "B" => $color[2], "Alpha" => 100];
        }

        $radio = (int)($alto / 2) - 15;
        $pie->draw2DRing($radio + 15, (int)($alto / 2), [
            "InnerRadius" => (int)($radio * 0.55),
            "OuterRadius" => $radio,
            "Border" => true, "BorderR" => 255, "BorderG" => 255, "BorderB" => 255,
            "WriteValues" => !$vacio,
            "ValuePosition" => PIE_VALUE_OUTSIDE,
            "ValueR" => self::TEXTO["R"], "ValueG" => self::TEXTO["G"], "ValueB" => self::TEXTO["B"],
        ]);
        $pie->drawPieLegend($radio * 2 + 60, (int)($alto / 2) - 30, [
            "Style" => LEGEND_NOBORDER,
            "BoxSize" => 12,
            "FontR" => self::TEXTO["R"], "FontG" => self::TEXTO["G"], "FontB" => self::TEXTO["B"],
        ]);

        return self::comoDataUri($imagen);
    }

    private static function imagen($ancho, $alto, Data $data)
    {
        $imagen = new Image($ancho, $alto, $data);
        $imagen->Antialias = true;
        $imagen->setFontProperties([
            "FontName" => self::FUENTE,
            "FontSize" => 9,
            "R" => self::TEXTO["R"], "G" => self::TEXTO["G"], "B" => self::TEXTO["B"],
        ]);
        return $imagen;
    }

    // Tope de la escala: el valor más alto redondeado hacia arriba (mínimo 4, así no queda vacía).
    private static function maximo(array $valores)
    {
        $maximo = max(array_merge([0], $valores));
        if ($maximo <= 4) {
            return 4;
        }
        $paso = $maximo <= 20 ? 2 : ($maximo <= 50 ? 5 : ($maximo <= 200 ? 20 : 50));
        return (int)(ceil($maximo / $paso) * $paso);
    }

    private static function comoDataUri(Image $imagen)
    {
        ob_start();
        imagepng($imagen->Picture);
        return "data:image/png;base64," . base64_encode(ob_get_clean());
    }
}
