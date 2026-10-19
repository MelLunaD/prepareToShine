<?php

namespace app\controller;

use Redirect;
use Request;
use Sesion;

class LoginController
{
    private $usuarioModel;
    private $validacionModel;
    private $render;

    public function __construct($usuarioModel, $validacionModel, $render)
    {
        $this->usuarioModel = $usuarioModel;
        $this->validacionModel = $validacionModel;
        $this->render = $render;
    }

    public function show()
    {
        if (Sesion::estaLogueada()) {
            Redirect::toIndex();
        }

        $data["aviso"] = Sesion::tomarFlash("aviso");
        $this->render->renderiza("login", $data);
    }

    public function login()
    {
        $nombreUsuario = trim((string)Request::post("usuario", ""));
        $usuario = $this->usuarioModel->autenticar($nombreUsuario, (string)Request::post("password", ""));

        if ($usuario === null) {
            $data["error"] = "Usuario o contraseña incorrectos.";
            $data["usuario"] = $nombreUsuario;
            $this->render->renderiza("login", $data);
            return;
        }

        // Cuenta creada pero sin validar: se manda un código nuevo (el anterior deja de
        // servir) y tiene que ingresarlo antes de poder entrar.
        if (!$usuario["validado"]) {
            Sesion::esperarValidacion($usuario["id"]);
            $enviado = $this->validacionModel->enviarCodigoNuevo($usuario);
            Sesion::flash("aviso", $enviado
                ? "Tu cuenta todavía no está validada. Te mandamos un código nuevo a tu mail."
                : "Tu cuenta todavía no está validada y no pudimos mandarte el código. Probá con \"Reenviar código\".");
            Redirect::to("/registro/validar");
        }

        Sesion::iniciar($usuario);
        Redirect::toIndex();
    }

    public function logout()
    {
        Sesion::cerrar();
        Redirect::to("/login");
    }
}
