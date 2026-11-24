<?php

namespace app\model;

// Código de validación de la cuenta: 6 dígitos que vencen a los 10 minutos.
// Pedir uno nuevo (reenviar o volver a loguearse) reemplaza al anterior,
// así el viejo deja de servir.
// El mismo código sirve para recuperar la contraseña: cambia solo el mail que se manda.
class ValidacionModel
{
    const MINUTOS_VIGENCIA = 10;

    // Para qué es el código: el asunto y las vistas del mail de cada caso.
    const MAILS = [
        "validar" => ["asunto" => "Tu código para Prepare to Shine", "vista" => "mailCodigo"],
        "recuperar" => ["asunto" => "Recuperá tu contraseña de Prepare to Shine", "vista" => "mailRecuperar"],
    ];

    private $database;
    private $mailer;
    private $render;

    public function __construct($database, $mailer, $render)
    {
        $this->database = $database;
        $this->mailer = $mailer;
        $this->render = $render;
    }

    // Genera un código nuevo, lo guarda y lo manda por mail. Devuelve true si el mail salió.
    // $motivo: "validar" (registro y login sin validar) o "recuperar" (olvidé mi contraseña).
    public function enviarCodigoNuevo(array $usuario, $motivo = "validar")
    {
        $mail = self::MAILS[$motivo];
        $codigo = str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);

        // El vencimiento lo calcula MySQL con su propio reloj, igual que la comparación
        // de confirmar(): así no importa si PHP y la base están en zonas horarias distintas.
        $this->database->execute(
            "UPDATE usuario SET codigo_validacion = ?, codigo_vence = DATE_ADD(NOW(), INTERVAL " . self::MINUTOS_VIGENCIA . " MINUTE)
             WHERE id = ?",
            [$codigo, $usuario["id"]]
        );

        $datosMail = [
            "nombre" => $usuario["nombre_completo"],
            "codigo" => $codigo,
            "minutos" => self::MINUTOS_VIGENCIA,
        ];

        return $this->mailer->enviar(
            $usuario["email"],
            $usuario["nombre_completo"],
            $mail["asunto"] . ": $codigo",
            $this->render->generar($mail["vista"], $datosMail),
            $this->render->generar($mail["vista"] . "Texto", $datosMail)
        );
    }

    // Devuelve "ok", "vencido" o "incorrecto". Si es "ok", la cuenta queda validada
    // (también al recuperar la contraseña: el código llegó a su mail, así que es suya).
    public function confirmar($usuarioId, $codigo)
    {
        $fila = $this->database->queryOne(
            "SELECT codigo_validacion, codigo_vence >= NOW() AS vigente FROM usuario WHERE id = ?",
            [$usuarioId]
        );

        if ($fila === null || $fila["codigo_validacion"] === null) {
            return "vencido";
        }

        if (!hash_equals($fila["codigo_validacion"], (string)$codigo)) {
            return "incorrecto";
        }

        if (!$fila["vigente"]) {
            return "vencido";
        }

        $this->database->execute(
            "UPDATE usuario SET validado = 1, codigo_validacion = NULL, codigo_vence = NULL WHERE id = ?",
            [$usuarioId]
        );

        return "ok";
    }

    // Anula el código actual (por ejemplo, después de muchos intentos fallidos).
    public function anularCodigo($usuarioId)
    {
        $this->database->execute(
            "UPDATE usuario SET codigo_validacion = NULL, codigo_vence = NULL WHERE id = ?",
            [$usuarioId]
        );
    }
}
