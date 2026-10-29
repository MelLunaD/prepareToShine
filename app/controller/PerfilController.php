<?php

namespace app\controller;

use Redirect;
use Request;
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

    // Perfil de otro jugador, al escanear el QR o entrar a /perfil/ver?usuario=xxx.
    // Como todo el sitio, pide haber iniciado sesión (lo controla el Router).
    public function ver()
    {
        $nombreUsuario = trim((string)Request::get("usuario", ""));
        if ($nombreUsuario === "") {
            Redirect::toIndex();
        }

        $datosPerfil = $this->formatearDatosPerfil(
            $this->perfilModel->obtenerPorUsuario($nombreUsuario)
        );

        if (!$datosPerfil) {
            Redirect::toIndex();
        }

        $data["tema"] = "shine";
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = Sesion::usuario();

        $data["perfil"] = $datosPerfil;
        $data["es_propio"] = false; // En el perfil de otro no se muestran sus datos privados ni la edición
        $data["puntaje_total"] = 0;
        $data["cantidad_partidas"] = 0;

        $this->render->renderiza("perfil", $data);
    }

    // Muestra el formulario de edición (GET /perfil/editar).
    // Los formularios de esta vista mandan a editarDatos y cambiarPassword.
    public function editar()
    {
        $usuarioSesion = Sesion::usuario();

        $data["tema"] = "shine";
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = $usuarioSesion;

        $data["perfil"] = $this->formatearDatosPerfil(
            $this->perfilModel->obtenerPorId($usuarioSesion["id"])
        );
        $data["errores"] = Sesion::tomarFlash("perfil_errores") ?? [];

        $this->render->renderiza("editarPerfil", $data);
    }

    public function editarDatos()
    {
        $usuarioSesion = Sesion::usuario();
        $idUsuario = $usuarioSesion["id"];
        $actual = $this->perfilModel->obtenerPorId($idUsuario);

        $nombreCompleto = trim((string)Request::post("nombre_completo", ""));

        if ($nombreCompleto === "" || mb_strlen($nombreCompleto) > 100) {
            Sesion::flash("perfil_errores", ["El nombre completo es obligatorio y debe tener hasta 100 caracteres."]);
            Redirect::to("/perfil/editar");
        }

        // La foto se guarda solo si el nombre está bien, para no dejar archivos sueltos.
        $resultadoFoto = $this->fotoModel->guardar($_FILES["foto"] ?? null);
        if ($resultadoFoto["error"] !== null) {
            Sesion::flash("perfil_errores", [$resultadoFoto["error"]]);
            Redirect::to("/perfil/editar");
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
        Redirect::to("/perfil");
    }

    public function cambiarPassword()
    {
        $usuarioSesion = Sesion::usuario();
        $idUsuario = $usuarioSesion["id"];

        $passwordActual = (string)Request::post("password_actual", "");
        $passwordNueva = (string)Request::post("password_nueva", "");
        $passwordNueva2 = (string)Request::post("password_nueva2", "");

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
            Redirect::to("/perfil/editar");
        }

        $nuevoHash = password_hash($passwordNueva, PASSWORD_DEFAULT);
        $this->perfilModel->actualizarPassword($idUsuario, $nuevoHash);

        Sesion::flash("perfil_exito", "Tu contraseña se actualizó correctamente.");
        Redirect::to("/perfil");
    }
}