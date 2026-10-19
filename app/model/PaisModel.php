<?php

namespace app\model;

class PaisModel
{
    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    public function listar()
    {
        return $this->database->queryAll("SELECT id, nombre, codigo FROM pais ORDER BY nombre");
    }

    public function existe($id)
    {
        return $this->database->queryOne("SELECT id FROM pais WHERE id = ?", [$id]) !== null;
    }

    public function idPorCodigo($codigo)
    {
        $fila = $this->database->queryOne("SELECT id FROM pais WHERE codigo = ?", [$codigo]);
        return $fila === null ? null : (int)$fila["id"];
    }
}
