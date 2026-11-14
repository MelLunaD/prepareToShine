<?php

class Request
{
    public static function get($key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public static function post($key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    // Las acciones que cambian datos (jugar, responder) solo se aceptan por POST,
    // así un link o una recarga no pueden dispararlas.
    public static function esPost()
    {
        return ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST";
    }
}
