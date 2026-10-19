<?php

namespace app\model;

// Login base del proyecto de ejemplo. Todavía NO está conectado (ver Configuration.php)
// porque no definimos la base de datos. Antes de usarlo:
//  - adaptar la consulta a la tabla de usuarios que definamos
//  - pasar de md5 a password_hash / password_verify
//  - usar prepared statements en lugar de concatenar el SQL
class LoginModel
{
    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    public function autenticar($usuario, $password)
    {
        $passwordHash = md5($password);

        // A propósito sin prepared statements: lo arreglamos en la clase de refactor
        $sql = "SELECT id, usuario FROM usuarios WHERE usuario = '$usuario' AND password = '$passwordHash'";

        return $this->database->queryOne($sql);
    }
}
