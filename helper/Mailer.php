<?php

require_once __DIR__ . "/../vendor/phpmailer/Exception.php";
require_once __DIR__ . "/../vendor/phpmailer/PHPMailer.php";
require_once __DIR__ . "/../vendor/phpmailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;

// Envía mails por SMTP con PHPMailer (InfinityFree no permite mail() de PHP).
// Usa la cuenta de Gmail del juego con una contraseña de aplicación, configurada
// en config.ini. Si mail_user está vacío (desarrollo local), no se manda nada:
// el mail se escribe en log/ para poder probar el registro sin Gmail.
class Mailer
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // Devuelve true si el mail salió (o quedó en el log en modo local).
    public function enviar($para, $nombre, $asunto, $html, $texto)
    {
        if (empty($this->config["mail_user"])) {
            Log::info("[MAIL LOCAL] Para: $para | Asunto: $asunto | $texto");
            return true;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->config["mail_host"] ?? "smtp.gmail.com";
            $mail->Port = (int)($this->config["mail_port"] ?? 587);
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Username = $this->config["mail_user"];
            $mail->Password = $this->config["mail_pass"];
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom($this->config["mail_user"], "Prepare to Shine");
            $mail->addAddress($para, $nombre);
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body = $html;
            $mail->AltBody = $texto;

            $mail->send();
            return true;
        } catch (Exception $e) {
            Log::error("No se pudo enviar el mail a $para: " . $mail->ErrorInfo);
            return false;
        }
    }
}
