<?php

namespace app\model;

// Guarda las fotos de perfil en public/img/perfiles/ con un nombre al azar.
// En la base queda solo la ruta pública (/public/img/perfiles/xxxx.jpg).
class FotoPerfilModel
{
    const TAMANIO_MAXIMO = 2 * 1024 * 1024; // 2 MB
    const TIPOS = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

    private $carpeta;

    public function __construct()
    {
        $this->carpeta = dirname(__DIR__, 2) . "/public/img/perfiles";
    }

    // $archivo es la entrada de $_FILES. Devuelve ["ruta" => ..., "error" => ...]:
    // sin archivo, las dos en null (la foto es opcional).
    public function guardar($archivo)
    {
        if (!is_array($archivo) || ($archivo["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ["ruta" => null, "error" => null];
        }

        if ($archivo["error"] === UPLOAD_ERR_INI_SIZE || $archivo["error"] === UPLOAD_ERR_FORM_SIZE
            || $archivo["size"] > self::TAMANIO_MAXIMO) {
            return ["ruta" => null, "error" => "La foto puede pesar hasta 2 MB."];
        }

        if ($archivo["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo["tmp_name"])) {
            return ["ruta" => null, "error" => "No pudimos subir la foto. Probá de nuevo."];
        }

        // El tipo se mira en el contenido del archivo, no en el nombre que manda el navegador.
        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo["tmp_name"]);
        if (!isset(self::TIPOS[$tipo]) || getimagesize($archivo["tmp_name"]) === false) {
            return ["ruta" => null, "error" => "La foto tiene que ser JPG, PNG o WEBP."];
        }

        if (!is_dir($this->carpeta)) {
            mkdir($this->carpeta, 0755, true);
        }

        $nombre = bin2hex(random_bytes(12)) . "." . self::TIPOS[$tipo];
        if (!move_uploaded_file($archivo["tmp_name"], $this->carpeta . "/" . $nombre)) {
            return ["ruta" => null, "error" => "No pudimos guardar la foto. Probá de nuevo."];
        }

        return ["ruta" => "/public/img/perfiles/" . $nombre, "error" => null];
    }

    public function borrar($ruta)
    {
        if ($ruta !== null) {
            @unlink($this->carpeta . "/" . basename($ruta));
        }
    }
}
