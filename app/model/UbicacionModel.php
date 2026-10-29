<?php

namespace app\model;

// Valida la ubicación elegida en el mapa (la completa public/js/mapa.js con Nominatim).
// La usan el registro y la edición del perfil.
class UbicacionModel
{
    const CAMPOS = ["latitud", "longitud", "ciudad", "provincia", "pais_codigo", "pais_nombre"];

    private $paisModel;

    public function __construct($paisModel)
    {
        $this->paisModel = $paisModel;
    }

    // Devuelve ["error" => texto o null, "pais_id" => id del país o null].
    public function validar(array $ubicacion)
    {
        $paisId = $this->paisModel->idPorCodigo(strtoupper($ubicacion["pais_codigo"]));

        if (!$this->esCoordenada($ubicacion["latitud"], 90) || !$this->esCoordenada($ubicacion["longitud"], 180)
            || $ubicacion["ciudad"] === "" || $ubicacion["pais_codigo"] === "") {
            $error = "Marcá en el mapa dónde vivís.";
        } elseif ($paisId === null) {
            $error = "Todavía no tenemos ese país en el juego. Elegí otro punto del mapa.";
        } elseif (mb_strlen($ubicacion["ciudad"]) > 100 || mb_strlen($ubicacion["provincia"]) > 100) {
            $error = "El nombre de la ciudad o la provincia es demasiado largo.";
        } else {
            $error = null;
        }

        return ["error" => $error, "pais_id" => $paisId];
    }

    // Texto para mostrar debajo del mapa: "Ciudad, Provincia, País".
    public function texto(array $ubicacion)
    {
        return implode(", ", array_filter([$ubicacion["ciudad"] ?? "", $ubicacion["provincia"] ?? "", $ubicacion["pais_nombre"] ?? ""]));
    }

    // Un número entre -$limite y $limite (latitud: 90, longitud: 180).
    private function esCoordenada($valor, $limite)
    {
        return is_numeric($valor) && abs((float)$valor) <= $limite;
    }
}
