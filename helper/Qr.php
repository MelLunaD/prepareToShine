<?php

// URL pública del perfil de un jugador y la imagen de su código QR (api.qrserver.com, sin librería).
// La usan el perfil y el miniperfil del ranking.
class Qr
{
    // $modo "die": el perfil se abre con el tema oscuro.
    public static function urlPerfil($nombreUsuario, $modo = "shine")
    {
        $protocolo = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        $url = $protocolo . "://" . $host . "/perfil/ver?usuario=" . urlencode($nombreUsuario);

        return $modo === "die" ? $url . "&modo=die" : $url;
    }

    public static function imagen($url, $tamanio = 160)
    {
        return "https://api.qrserver.com/v1/create-qr-code/?size={$tamanio}x{$tamanio}&margin=6&data=" . urlencode($url);
    }
}
