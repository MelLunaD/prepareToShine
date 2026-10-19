<?php

// Todo lo que el juego guarda en $_SESSION pasa por acá, así las claves
// están en un solo lugar.
//  - "usuario": el jugador logueado y validado (id, usuario, foto, rol).
//  - "validar_id": cuenta creada o logueada que todavía no ingresó su código.
//  - "intentos_fallidos": códigos mal ingresados desde el último código enviado.
//  - "flash": mensajes que se muestran una sola vez (después de un redirect).
class Sesion
{
    public static function iniciar(array $usuario)
    {
        // Id de sesión nuevo al loguearse: evita que alguien fije uno de antemano.
        session_regenerate_id(true);
        unset($_SESSION["validar_id"], $_SESSION["intentos_fallidos"]);
        $_SESSION["usuario"] = [
            "id" => (int)$usuario["id"],
            "usuario" => $usuario["usuario"],
            "foto" => $usuario["foto"] ?: "/public/img/avatar.svg",
            "rol" => $usuario["rol"],
        ];
    }

    public static function estaLogueada()
    {
        return isset($_SESSION["usuario"]);
    }

    // Datos del jugador logueado, o null si no hay sesión.
    public static function usuario()
    {
        return $_SESSION["usuario"] ?? null;
    }

    public static function cerrar()
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function esperarValidacion($usuarioId)
    {
        $_SESSION["validar_id"] = (int)$usuarioId;
        $_SESSION["intentos_fallidos"] = 0;
    }

    public static function idAValidar()
    {
        return $_SESSION["validar_id"] ?? null;
    }

    // Suma un código mal ingresado y devuelve cuántos van.
    public static function sumarIntentoFallido()
    {
        $_SESSION["intentos_fallidos"] = ($_SESSION["intentos_fallidos"] ?? 0) + 1;
        return $_SESSION["intentos_fallidos"];
    }

    public static function reiniciarIntentosFallidos()
    {
        $_SESSION["intentos_fallidos"] = 0;
    }

    public static function flash($clave, $mensaje)
    {
        $_SESSION["flash"][$clave] = $mensaje;
    }

    // Devuelve el mensaje y lo borra, para que no se repita al recargar.
    public static function tomarFlash($clave)
    {
        $mensaje = $_SESSION["flash"][$clave] ?? null;
        unset($_SESSION["flash"][$clave]);
        return $mensaje;
    }
}
