<?php

require_once __DIR__ . "/../vendor/dompdf/autoload.inc.php";

use Dompdf\Dompdf;
use Dompdf\Options;

// Encapsula Dompdf (vendor/dompdf, versión 3.1.4 con sus dependencias, sin Composer):
// recibe el HTML ya armado con Mustache y lo manda al navegador como PDF.
// Dompdf entiende CSS 2.1: nada de Flexbox ni Grid, la vista del PDF va con tablas.
class Pdf
{
    public static function descargar($html, $nombreArchivo)
    {
        $opciones = new Options();
        // No busca nada en internet: las imágenes de los gráficos van dentro del HTML (data:).
        $opciones->set("isRemoteEnabled", false);
        // DejaVu Sans viene con Dompdf y tiene acentos y eñes.
        $opciones->set("defaultFont", "DejaVu Sans");

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, "UTF-8");
        $dompdf->setPaper("A4", "portrait");
        $dompdf->render();

        // Attachment: true = se descarga; false = el navegador lo abre en una pestaña.
        $dompdf->stream($nombreArchivo, ["Attachment" => false]);
    }
}
