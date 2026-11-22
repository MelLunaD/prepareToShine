<?php

class MyDatabase
{
    const ZONA_HORARIA = "-03:00";

    private $conexion;

    public function __construct($host, $user, $pass, $db)
    {
        $this->conexion = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Todo el juego va en hora argentina: NOW(), CURDATE() y CURRENT_TIMESTAMP dependen de la
        // zona de la conexión, y el MySQL de InfinityFree está en la del servidor (UTC-8). Así el
        // historial muestra la hora de acá y la semana arranca el lunes 00:00 de Argentina.
        // Se usa el desfasaje y no "America/Argentina/Buenos_Aires" porque el hosting no tiene
        // cargadas las zonas con nombre (Argentina no tiene horario de verano).
        $this->conexion->exec("SET time_zone = '" . self::ZONA_HORARIA . "'");
    }

    // Los valores van siempre en $params, nunca concatenados en el SQL:
    // queryOne("SELECT * FROM usuario WHERE usuario = ?", [$usuario])
    public function queryOne(string $sql, array $params = [])
    {
        $fila = $this->ejecutar($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    public function queryAll(string $sql, array $params = [])
    {
        return $this->ejecutar($sql, $params)->fetchAll();
    }

    // Para INSERT, UPDATE y DELETE: devuelve la cantidad de filas afectadas.
    public function execute(string $sql, array $params = [])
    {
        return $this->ejecutar($sql, $params)->rowCount();
    }

    public function lastInsertId()
    {
        return $this->conexion->lastInsertId();
    }

    private function ejecutar(string $sql, array $params)
    {
        // Se loguea la consulta sin los valores, para no guardar datos de los usuarios en el log.
        Log::info($sql);
        $sentencia = $this->conexion->prepare($sql);
        $sentencia->execute($params);
        return $sentencia;
    }
}
