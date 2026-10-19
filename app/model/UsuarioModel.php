<?php

namespace app\model;

class UsuarioModel
{
    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    // Devuelve el usuario si la contraseña es correcta (validado o no), o null.
    // El usuario 'sistema' tiene un hash inválido, así que nunca pasa.
    public function autenticar($usuario, $password)
    {
        $fila = $this->database->queryOne(
            "SELECT id, usuario, nombre_completo, email, password_hash, foto, rol, validado FROM usuario WHERE usuario = ?",
            [$usuario]
        );

        if ($fila === null || !password_verify($password, $fila["password_hash"])) {
            return null;
        }

        return $fila;
    }

    public function buscarPorId($id)
    {
        return $this->database->queryOne(
            "SELECT id, usuario, nombre_completo, email, foto, rol, validado FROM usuario WHERE id = ?",
            [$id]
        );
    }

    public function existeUsuario($usuario)
    {
        return $this->database->queryOne("SELECT id FROM usuario WHERE usuario = ?", [$usuario]) !== null;
    }

    public function existeEmail($email)
    {
        return $this->database->queryOne("SELECT id FROM usuario WHERE email = ?", [$email]) !== null;
    }

    // Crea la cuenta sin validar y devuelve su id.
    public function crear(array $datos)
    {
        $this->database->execute(
            "INSERT INTO usuario (nombre_completo, anio_nacimiento, sexo, pais_id, email, usuario, password_hash, foto, validado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)",
            [
                $datos["nombre_completo"],
                $datos["anio_nacimiento"],
                $datos["sexo"],
                $datos["pais_id"],
                $datos["email"],
                $datos["usuario"],
                password_hash($datos["password"], PASSWORD_DEFAULT),
                $datos["foto"],
            ]
        );

        return (int)$this->database->lastInsertId();
    }
}
