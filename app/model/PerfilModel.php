<?php

namespace app\model;

class PerfilModel
{
    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    public function obtenerPorId($id)
    {
        $sql = "SELECT u.id, u.nombre_completo, u.usuario, u.email, u.anio_nacimiento, u.sexo, u.foto, u.fecha_alta, 
                       p.nombre AS pais_nombre, p.codigo AS pais_codigo 
                FROM usuario u 
                INNER JOIN pais p ON u.pais_id = p.id 
                WHERE u.id = ?";

        return $this->database->queryOne($sql, [$id]);
    }

    public function actualizarDatos($id, $nombreCompleto, $foto)
    {
        $sql = "UPDATE usuario SET nombre_completo = ?, foto = ? WHERE id = ?";
        $this->database->execute($sql, [$nombreCompleto, $foto, $id]);
    }

    public function obtenerPasswordHash($id)
    {
        $sql = "SELECT password_hash FROM usuario WHERE id = ?";
        $fila = $this->database->queryOne($sql, [$id]);
        return $fila["password_hash"] ?? null;
    }

    public function actualizarPassword($id, $nuevoHash)
    {
        $sql = "UPDATE usuario SET password_hash = ? WHERE id = ?";
        $this->database->execute($sql, [$nuevoHash, $id]);
    }

    public function obtenerPorUsuario($usuario)
    {
        $sql = "SELECT u.id, u.nombre_completo, u.usuario, u.email, u.anio_nacimiento, u.sexo, u.foto, u.fecha_alta, 
                       p.nombre AS pais_nombre, p.codigo AS pais_codigo 
                FROM usuario u 
                INNER JOIN pais p ON u.pais_id = p.id 
                WHERE u.usuario = ?";

        return $this->database->queryOne($sql, [$usuario]);
    }
}