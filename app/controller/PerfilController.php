<?php

namespace app\controller;

use app\model\UbicacionModel;
use Qr;
use Redirect;
use Request;
use Sesion;

class PerfilController
{
    private $perfilModel;
    private $fotoModel;
    private $ubicacionModel;
    private $rankingModel;
    private $solicitudModel;
    private $render;

    // El perfil tiene versión Shine y versión Die (se pasa con el secreto de los 5 clicks o con
    // ?modo=die): se mandan los datos de los dos modos y el CSS muestra el del tema activo.
    private function resumenPorModo($usuarioId)
    {
        return [
            "shine" => $this->rankingModel->resumen($usuarioId, "shine"),
            "die" => $this->rankingModel->resumen($usuarioId, "die"),
        ];
    }

    private function temaPedido()
    {
        return Request::get("modo") === "die" ? "souls" : "shine";
    }

    public function __construct($perfilModel, $fotoModel, $ubicacionModel, $rankingModel, $solicitudModel, $render)
    {
        $this->solicitudModel = $solicitudModel;
        $this->perfilModel = $perfilModel;
        $this->fotoModel = $fotoModel;
        $this->ubicacionModel = $ubicacionModel;
        $this->rankingModel = $rankingModel;
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

        // Los usuarios registrados antes del mapa no tienen ubicación: el mapa no se muestra.
        $datosPerfil["tiene_ubicacion"] = $datosPerfil["latitud"] !== null && $datosPerfil["longitud"] !== null;
        $datosPerfil["ubicacion_texto"] = implode(", ", array_filter([$datosPerfil["ciudad"], $datosPerfil["provincia"]]));
        $datosPerfil["ubicacion_busqueda"] = implode(", ", array_filter([$datosPerfil["ciudad"], $datosPerfil["provincia"], $datosPerfil["pais_nombre"]]));

        return $datosPerfil;
    }

    private function generarUrlQr($nombreUsuario)
    {
        $urlPerfilPublico = Qr::urlPerfil($nombreUsuario);

        return [
            "url_publica" => $urlPerfilPublico,
            "qr_imagen" => Qr::imagen($urlPerfilPublico),
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

        $data["tema"] = $this->temaPedido();
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = $usuarioSesion;

        $data["perfil"] = $datosPerfil;
        $data["es_propio"] = true;
        $data["qr_imagen"] = $qr["qr_imagen"] ?? null;
        $data["url_publica"] = $qr["url_publica"] ?? null;
        $data["resumen"] = $datosPerfil ? $this->resumenPorModo($datosPerfil["id"]) : null;

        $data["exito"] = Sesion::tomarFlash("perfil_exito");
        $data["usaMapa"] = true;
        // El perfil ya trae el rol de la base: si cambió (por ejemplo, a mano en la base),
        // se actualiza la sesión, así el recuadro de moderar y el menú muestran lo que corresponde.
        if ($datosPerfil && $datosPerfil["rol"] !== $usuarioSesion["rol"]) {
            Sesion::cambiarRol($datosPerfil["rol"]);
            $usuarioSesion = Sesion::usuario();
            $data["sesion"] = $usuarioSesion;
        }
        $data["moderar"] = $this->datosDeModerar($usuarioSesion);

        $this->render->renderiza("perfil", $data);
    }

    // "Quiero moderar": el botón solo aparece si cumple el requisito (SolicitudEditorModel).
    public function moderar()
    {
        if (Request::esPost() && $this->solicitudModel->pedir(Sesion::usuario())) {
            Sesion::revisarSolicitud(true); // el lobby se fija si ya la aprobaron
            Sesion::flash("perfil_exito", "¡Listo! Tu solicitud llegó a los administradores.");
        }
        Redirect::to("/perfil" . (Request::post("modo") === "die" ? "?modo=die" : ""));
    }

    private function datosDeModerar(array $usuario)
    {
        $estado = $this->solicitudModel->estadoParaElPerfil($usuario);
        if ($estado === null) {
            return null;
        }

        $ultima = $this->solicitudModel->ultima($usuario["id"]);
        return [
            "puede" => $estado === "puede",
            "pendiente" => $estado === "pendiente",
            "rechazada" => $estado === "rechazada",
            "fecha" => $ultima ? date("d/m", strtotime($ultima["fecha"])) : null,
            "modo" => $this->temaPedido() === "souls" ? "die" : "shine",
        ];
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

        // A los demás solo se les muestra la localidad: el punto exacto no llega a la vista.
        unset($datosPerfil["latitud"], $datosPerfil["longitud"]);

        $data["tema"] = $this->temaPedido();
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = Sesion::usuario();

        $data["perfil"] = $datosPerfil;
        $data["es_propio"] = false; // En el perfil de otro no se muestran sus datos privados ni la edición
        $data["resumen"] = $this->resumenPorModo($datosPerfil["id"]);
        $data["usaMapa"] = true;

        $this->render->renderiza("perfil", $data);
    }

    // Muestra el formulario de edición (GET /perfil/editar).
    // Los formularios de esta vista mandan a editarDatos y cambiarPassword.
    public function editar()
    {
        $usuarioSesion = Sesion::usuario();

        $data["tema"] = $this->temaPedido();
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = $usuarioSesion;

        $data["perfil"] = $this->formatearDatosPerfil(
            $this->perfilModel->obtenerPorId($usuarioSesion["id"])
        );
        $data["errores"] = Sesion::tomarFlash("perfil_errores") ?? [];
        $data["usaMapa"] = true;

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

        // Ubicación: si no tocó el mapa (los campos llegan vacíos), queda la que tenía.
        $ubicacion = [];
        foreach (UbicacionModel::CAMPOS as $campo) {
            $ubicacion[$campo] = trim((string)Request::post($campo, ""));
        }
        $cambiaUbicacion = $ubicacion["latitud"] !== "";
        if ($cambiaUbicacion) {
            $validacion = $this->ubicacionModel->validar($ubicacion);
            if ($validacion["error"] !== null) {
                Sesion::flash("perfil_errores", [$validacion["error"]]);
                Redirect::to("/perfil/editar");
            }
        }

        // La foto se guarda solo si lo demás está bien, para no dejar archivos sueltos.
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
        if ($cambiaUbicacion) {
            $this->perfilModel->actualizarUbicacion($idUsuario, $validacion["pais_id"], $ubicacion);
        }
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