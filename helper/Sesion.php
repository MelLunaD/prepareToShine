<?php

// Todo lo que el juego guarda en $_SESSION pasa por acá, así las claves
// están en un solo lugar.
//  - "usuario": el jugador logueado y validado (id, usuario, foto, rol).
//  - "validar_id": cuenta creada o logueada que todavía no ingresó su código.
//  - "intentos_fallidos": códigos mal ingresados desde el último código enviado.
//  - "flash": mensajes que se muestran una sola vez (después de un redirect).
//  - "revisar_solicitud": si el lobby tiene que mirar en la base si aprobaron su "Quiero moderar".
class Sesion
{
    public static function iniciar(array $usuario)
    {
        // Id de sesión nuevo al loguearse: evita que alguien fije uno de antemano.
        session_regenerate_id(true);
        unset($_SESSION["validar_id"], $_SESSION["intentos_fallidos"], $_SESSION["revisar_solicitud"]);
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
    // "esEditor" y "esAdmin" los usa el header para mostrar "Moderar" o "Administrar" en el menú.
    public static function usuario()
    {
        if (!isset($_SESSION["usuario"])) {
            return null;
        }
        return $_SESSION["usuario"] + [
            "esEditor" => $_SESSION["usuario"]["rol"] === "editor",
            "esAdmin" => $_SESSION["usuario"]["rol"] === "admin",
        ];
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

    // El rol se lee al iniciar sesión. Si un administrador aprueba su "Quiero moderar", el lobby
    // lo vuelve a leer, pero solo para quien tiene una solicitud pendiente: la primera vez que
    // entra al lobby en la sesión se fija (todavía no se sabe), y después solo si quedó pendiente.
    public static function hayQueRevisarSolicitud()
    {
        return $_SESSION["revisar_solicitud"] ?? true;
    }

    public static function revisarSolicitud($revisar)
    {
        $_SESSION["revisar_solicitud"] = (bool)$revisar;
    }

    public static function cambiarRol($rol)
    {
        if (isset($_SESSION["usuario"])) {
            $_SESSION["usuario"]["rol"] = $rol;
        }
    }

    public static function actualizarFoto($foto)
    {
        if (isset($_SESSION["usuario"])) {
            $_SESSION["usuario"]["foto"] = $foto ?: "/public/img/avatar.svg";
        }
    }
}
