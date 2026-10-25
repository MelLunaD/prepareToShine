<?php

namespace app\controller;

use Sesion;

class PerfilController
{
    private $perfilModel;
    private $fotoModel;
    private $render;

    public function __construct($perfilModel, $fotoModel, $render)
    {
        $this->perfilModel = $perfilModel;
        $this->fotoModel = $fotoModel;
        $this->render = $render;
    }

    private function formatearDatosPerfil($datosPerfil)
    {
        if (!$datosPerfil) {
            return null;
        }

        if (empty($datosPerfil["foto"])) {
            $datosPerfil["foto"] = "/public/img/avatar.svg";
        }

        $datosPerfil["pais_codigo_min"] = strtolower($datosPerfil["pais_codigo"]);

        $mapaSexo = [
            "masculino" => "Masculino",
            "femenino" => "Femenino",
            "no_informa" => "Prefiero no decirlo"
        ];
        $datosPerfil["sexo_legible"] = $mapaSexo[$datosPerfil["sexo"]] ?? $datosPerfil["sexo"];

        $timestamp = strtotime($datosPerfil["fecha_alta"]);
        $datosPerfil["fecha_alta_formateada"] = $timestamp ? date("d/m/Y", $timestamp) : $datosPerfil["fecha_alta"];

        return $datosPerfil;
    }

    private function generarUrlQr($nombreUsuario)
    {
        $protocolo = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        $urlPerfilPublico = $protocolo . "://" . $host . "/perfil/ver?usuario=" . urlencode($nombreUsuario);

        return [
            "url_publica" => $urlPerfilPublico,
            "qr_imagen" => "https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=6&data=" . urlencode($urlPerfilPublico)
        ];
    }

    public function show()
    {
        if (!Sesion::estaLogueada()) {
            header("Location: /login");
            exit;
        }

        $usuarioSesion = Sesion::usuario();
        $idUsuario = $usuarioSesion["id"];

        $datosPerfil = $this->formatearDatosPerfil(
            $this->perfilModel->obtenerPorId($idUsuario)
        );

        $qr = $datosPerfil ? $this->generarUrlQr($datosPerfil["usuario"]) : null;

        $data["tema"] = "shine";
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = $usuarioSesion;

        $data["perfil"] = $datosPerfil;
        $data["es_propio"] = true;
        $data["qr_imagen"] = $qr["qr_imagen"] ?? null;
        $data["url_publica"] = $qr["url_publica"] ?? null;
        $data["puntaje_total"] = 0;
        $data["cantidad_partidas"] = 0;

        $data["exito"] = Sesion::tomarFlash("perfil_exito");

        $this->render->renderiza("perfil", $data);
    }

    // Vista pública al escanear el QR o entrar a /perfil/ver?usuario=xxx
    public function ver()
    {
        $nombreUsuario = trim($_GET["usuario"] ?? "");
        if ($nombreUsuario === "") {
            header("Location: /");
            exit;
        }

        $datosPerfil = $this->formatearDatosPerfil(
            $this->perfilModel->obtenerPorUsuario($nombreUsuario)
        );

        if (!$datosPerfil) {
            header("Location: /");
            exit;
        }

        $data["tema"] = "shine";
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = Sesion::usuario();

        $data["perfil"] = $datosPerfil;
        $data["es_propio"] = false; // En el enlace público nunca se muestran datos privados ni edición
        $data["puntaje_total"] = 0;
        $data["cantidad_partidas"] = 0;

        $this->render->renderiza("perfil", $data);
    }

    public function editarDatos()
    {
        if (!Sesion::estaLogueada()) {
            header("Location: /login");
            exit;
        }

        $usuarioSesion = Sesion::usuario();
        $idUsuario = $usuarioSesion["id"];
        $actual = $this->perfilModel->obtenerPorId($idUsuario);

        $nombreCompleto = trim($_POST["nombre_completo"] ?? "");
        $errores = [];

        if ($nombreCompleto === "" || mb_strlen($nombreCompleto) > 100) {
            $errores[] = "El nombre completo es obligatorio y debe tener hasta 100 caracteres.";
        }

        $resultadoFoto = $this->fotoModel->guardar($_FILES["foto"] ?? null);
        if ($resultadoFoto["error"] !== null) {
            $errores[] = $resultadoFoto["error"];
        }

        if (!empty($errores)) {
            Sesion::flash("perfil_errores", $errores);
            header("Location: /perfil/editar");
            exit;
        }

        $fotoFinal = $actual["foto"];
        if ($resultadoFoto["ruta"] !== null) {
            if (!empty($actual["foto"])) {
                $this->fotoModel->borrar($actual["foto"]);
            }
            $fotoFinal = $resultadoFoto["ruta"];
        }

        $this->perfilModel->actualizarDatos($idUsuario, $nombreCompleto, $fotoFinal);
        Sesion::actualizarFoto($fotoFinal);

        Sesion::flash("perfil_exito", "Tus datos de perfil se actualizaron correctamente.");
        header("Location: /perfil");
        exit;
    }

    public function cambiarPassword()
    {
        if (!Sesion::estaLogueada()) {
            header("Location: /login");
            exit;
        }

        $usuarioSesion = Sesion::usuario();
        $idUsuario = $usuarioSesion["id"];

        $passwordActual = $_POST["password_actual"] ?? "";
        $passwordNueva = $_POST["password_nueva"] ?? "";
        $passwordNueva2 = $_POST["password_nueva2"] ?? "";

        $errores = [];
        $hashGuardado = $this->perfilModel->obtenerPasswordHash($idUsuario);

        if (!$hashGuardado || !password_verify($passwordActual, $hashGuardado)) {
            $errores[] = "La contraseña actual es incorrecta.";
        }

        if (strlen($passwordNueva) < 8) {
            $errores[] = "La nueva contraseña debe tener al menos 8 caracteres.";
        }

        if ($passwordNueva !== $passwordNueva2) {
            $errores[] = "Las contraseñas nuevas no coinciden.";
        }

        if (!empty($errores)) {
            Sesion::flash("perfil_errores", $errores);
            header("Location: /perfil/editar");
            exit;
        }

        $nuevoHash = password_hash($passwordNueva, PASSWORD_DEFAULT);
        $this->perfilModel->actualizarPassword($idUsuario, $nuevoHash);

        Sesion::flash("perfil_exito", "Tu contraseña se actualizó correctamente.");
        header("Location: /perfil");
        exit;
    }
}