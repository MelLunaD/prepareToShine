<?php

namespace app\controller;

use app\model\ValidacionModel;
use Redirect;
use Request;
use Sesion;

// "¿Olvidaste tu contraseña?": pide el mail o el usuario, manda el mismo código de 6 números
// que el registro y con ese código se elige una contraseña nueva.
// Es público (está en CONTROLLERS_PUBLICOS): quien lo usa no puede iniciar sesión.
class RecuperarController
{
    const INTENTOS_MAXIMOS = 5;
    const AVISO_ENVIADO = "Si hay una cuenta con ese dato, te mandamos un código a su mail.";

    private $usuarioModel;
    private $validacionModel;
    private $render;

    public function __construct($usuarioModel, $validacionModel, $render)
    {
        $this->usuarioModel = $usuarioModel;
        $this->validacionModel = $validacionModel;
        $this->render = $render;
    }

    // Paso 1: el formulario del mail o usuario.
    public function show()
    {
        if (Sesion::estaLogueada()) {
            Redirect::toIndex();
        }

        $data["error"] = Sesion::tomarFlash("error");
        $this->render->renderiza("recuperar", $data);
    }

    public function enviar()
    {
        if (Sesion::estaLogueada()) {
            Redirect::toIndex();
        }

        $dato = trim((string)Request::post("dato", ""));
        if ($dato === "") {
            Sesion::flash("error", "Escribí tu mail o tu nombre de usuario.");
            Redirect::to("/recuperar");
        }

        // Exista o no la cuenta, la respuesta es la misma: así nadie puede usar esta
        // pantalla para averiguar qué mails o usuarios están registrados.
        $usuario = $this->usuarioModel->buscarPorUsuarioOEmail($dato);
        Sesion::esperarRecuperacion($usuario["id"] ?? 0);
        if ($usuario !== null) {
            $this->validacionModel->enviarCodigoNuevo($usuario, "recuperar");
        }

        Sesion::flash("aviso", self::AVISO_ENVIADO);
        Redirect::to("/recuperar/codigo");
    }

    // Paso 2: el código y la contraseña nueva.
    public function codigo()
    {
        $this->idPendiente();

        $data["aviso"] = Sesion::tomarFlash("aviso");
        $data["error"] = Sesion::tomarFlash("error");
        $data["minutos"] = ValidacionModel::MINUTOS_VIGENCIA;
        $this->render->renderiza("recuperarCodigo", $data);
    }

    public function confirmar()
    {
        $id = $this->idPendiente();
        $codigo = preg_replace('/\D/', '', (string)Request::post("codigo", ""));
        $password = (string)Request::post("password", "");
        $password2 = (string)Request::post("password2", "");

        // Primero la contraseña: si el código estuviera bien, confirmar() lo gasta.
        if (strlen($password) < 8) {
            $this->volverConError("La contraseña tiene que tener al menos 8 caracteres.");
        }
        if ($password !== $password2) {
            $this->volverConError("Las contraseñas no coinciden.");
        }

        // Sin cuenta (id 0) el código nunca puede estar bien, pero la respuesta es la misma.
        $resultado = $id === 0 ? "incorrecto" : $this->validacionModel->confirmar($id, $codigo);

        if ($resultado === "ok") {
            $this->usuarioModel->cambiarPassword($id, $password);
            Sesion::terminarRecuperacion();
            Sesion::flash("aviso", "Tu contraseña se cambió. Ya podés entrar con la nueva.");
            Redirect::to("/login");
        }

        if ($resultado === "incorrecto") {
            // Igual que en el registro: después de varios errores el código se anula.
            if (Sesion::sumarIntentoFallido() >= self::INTENTOS_MAXIMOS) {
                if ($id !== 0) {
                    $this->validacionModel->anularCodigo($id);
                }
                $this->volverConError("Te equivocaste demasiadas veces. Pedí un código nuevo con \"Reenviar código\".");
            }
            $this->volverConError("El código no es correcto.");
        }

        $this->volverConError("El código venció. Pedí uno nuevo con \"Reenviar código\".");
    }

    public function reenviar()
    {
        $id = $this->idPendiente();

        Sesion::reiniciarIntentosFallidos();
        $usuario = $id === 0 ? null : $this->usuarioModel->buscarPorId($id);
        if ($usuario !== null) {
            $this->validacionModel->enviarCodigoNuevo($usuario, "recuperar");
        }

        Sesion::flash("aviso", "Si hay una cuenta con ese dato, te mandamos un código nuevo. El anterior ya no sirve.");
        Redirect::to("/recuperar/codigo");
    }

    // Sin haber pasado por el paso 1 no hay código que pedir: vuelve al principio.
    private function idPendiente()
    {
        if (Sesion::estaLogueada()) {
            Redirect::toIndex();
        }

        $id = Sesion::idARecuperar();
        if ($id === null) {
            Redirect::to("/recuperar");
        }

        return $id;
    }

    private function volverConError($mensaje)
    {
        Sesion::flash("error", $mensaje);
        Redirect::to("/recuperar/codigo");
    }
}
