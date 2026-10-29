<?php

namespace app\controller;

use app\model\ValidacionModel;
use Redirect;
use Request;
use Sesion;

class RegistroController
{
    const EDAD_MINIMA = 12;
    const INTENTOS_MAXIMOS = 5;
    const SEXOS = [
        "masculino" => "Masculino",
        "femenino" => "Femenino",
        "no_informa" => "Prefiero no decirlo",
    ];

    private $usuarioModel;
    private $paisModel;
    private $fotoPerfilModel;
    private $validacionModel;
    private $render;

    public function __construct($usuarioModel, $paisModel, $fotoPerfilModel, $validacionModel, $render)
    {
        $this->usuarioModel = $usuarioModel;
        $this->paisModel = $paisModel;
        $this->fotoPerfilModel = $fotoPerfilModel;
        $this->validacionModel = $validacionModel;
        $this->render = $render;
    }

    public function show()
    {
        if (Sesion::estaLogueada()) {
            Redirect::toIndex();
        }

        $this->mostrarFormulario([], []);
    }

    public function crear()
    {
        if (Sesion::estaLogueada()) {
            Redirect::toIndex();
        }

        $datos = [
            "nombre_completo" => trim((string)Request::post("nombre_completo", "")),
            "anio_nacimiento" => trim((string)Request::post("anio_nacimiento", "")),
            "sexo" => (string)Request::post("sexo", ""),
            // Ubicación elegida en el mapa: la completa public/js/mapa.js con Nominatim.
            "latitud" => trim((string)Request::post("latitud", "")),
            "longitud" => trim((string)Request::post("longitud", "")),
            "ciudad" => trim((string)Request::post("ciudad", "")),
            "provincia" => trim((string)Request::post("provincia", "")),
            "pais_codigo" => strtoupper(trim((string)Request::post("pais_codigo", ""))),
            "pais_nombre" => trim((string)Request::post("pais_nombre", "")),
            "email" => trim((string)Request::post("email", "")),
            "usuario" => trim((string)Request::post("usuario", "")),
            "password" => (string)Request::post("password", ""),
            "password2" => (string)Request::post("password2", ""),
        ];

        $datos["pais_id"] = $this->paisModel->idPorCodigo($datos["pais_codigo"]);
        $errores = $this->erroresDelFormulario($datos);

        // La foto se guarda solo si todo lo demás está bien, para no dejar archivos sueltos.
        $datos["foto"] = null;
        if (empty($errores)) {
            $foto = $this->fotoPerfilModel->guardar($_FILES["foto"] ?? null);
            if ($foto["error"] !== null) {
                $errores[] = $foto["error"];
            }
            $datos["foto"] = $foto["ruta"];
        }

        if (!empty($errores)) {
            $this->mostrarFormulario($errores, $datos);
            return;
        }

        try {
            $id = $this->usuarioModel->crear($datos);
        } catch (\PDOException $e) {
            // Otro se registró con el mismo usuario o mail entre la validación y el INSERT.
            $this->fotoPerfilModel->borrar($datos["foto"]);
            $this->mostrarFormulario(["Ese usuario o mail ya está registrado."], $datos);
            return;
        }

        // La cuenta ya existe (sin validar): si se va de la página, la valida en el próximo login.
        Sesion::esperarValidacion($id);
        $enviado = $this->validacionModel->enviarCodigoNuevo($this->usuarioModel->buscarPorId($id));
        Sesion::flash("aviso", $enviado
            ? "¡Cuenta creada! Te mandamos un código a " . $datos["email"] . "."
            : "Cuenta creada, pero no pudimos mandarte el código. Probá con \"Reenviar código\".");
        Redirect::to("/registro/validar");
    }

    public function validar()
    {
        $usuario = $this->usuarioPendiente();

        $data["aviso"] = Sesion::tomarFlash("aviso");
        $data["error"] = Sesion::tomarFlash("error");
        $data["email"] = $usuario["email"];
        $data["minutos"] = ValidacionModel::MINUTOS_VIGENCIA;
        $this->render->renderiza("validar", $data);
    }

    public function confirmar()
    {
        $usuario = $this->usuarioPendiente();
        $codigo = preg_replace('/\D/', '', (string)Request::post("codigo", ""));

        $resultado = $this->validacionModel->confirmar($usuario["id"], $codigo);

        if ($resultado === "ok") {
            Sesion::iniciar($usuario);
            Redirect::toIndex();
        }

        if ($resultado === "incorrecto") {
            // Con 6 dígitos, probar al azar sin límite terminaría acertando: después
            // de varios errores el código se anula y hay que pedir otro.
            if (Sesion::sumarIntentoFallido() >= self::INTENTOS_MAXIMOS) {
                $this->validacionModel->anularCodigo($usuario["id"]);
                Sesion::flash("error", "Te equivocaste demasiadas veces. Pedí un código nuevo con \"Reenviar código\".");
            } else {
                Sesion::flash("error", "El código no es correcto.");
            }
        } else {
            Sesion::flash("error", "El código venció. Pedí uno nuevo con \"Reenviar código\".");
        }

        Redirect::to("/registro/validar");
    }

    public function reenviar()
    {
        $usuario = $this->usuarioPendiente();

        Sesion::reiniciarIntentosFallidos();
        $enviado = $this->validacionModel->enviarCodigoNuevo($usuario);
        if ($enviado) {
            Sesion::flash("aviso", "Te mandamos un código nuevo. El anterior ya no sirve.");
        } else {
            Sesion::flash("error", "No pudimos mandar el mail. Probá de nuevo en un rato.");
        }
        Redirect::to("/registro/validar");
    }

    // La cuenta que está esperando su código. Sin una, no hay nada que validar.
    private function usuarioPendiente()
    {
        $id = Sesion::idAValidar();
        $usuario = $id === null ? null : $this->usuarioModel->buscarPorId($id);

        if ($usuario === null || $usuario["validado"]) {
            Redirect::to("/login");
        }

        return $usuario;
    }

    private function erroresDelFormulario(array $datos)
    {
        $errores = [];
        $anioActual = (int)date("Y");

        if (mb_strlen($datos["nombre_completo"]) < 3 || mb_strlen($datos["nombre_completo"]) > 100) {
            $errores[] = "Escribí tu nombre completo.";
        }

        if (!ctype_digit($datos["anio_nacimiento"])
            || (int)$datos["anio_nacimiento"] < $anioActual - 120
            || (int)$datos["anio_nacimiento"] > $anioActual) {
            $errores[] = "El año de nacimiento no es válido.";
        } elseif ($anioActual - (int)$datos["anio_nacimiento"] < self::EDAD_MINIMA) {
            $errores[] = "Tenés que tener al menos " . self::EDAD_MINIMA . " años para jugar.";
        }

        if (!isset(self::SEXOS[$datos["sexo"]])) {
            $errores[] = "Elegí una opción de sexo.";
        }

        if (!$this->esCoordenada($datos["latitud"], 90) || !$this->esCoordenada($datos["longitud"], 180)
            || $datos["ciudad"] === "" || $datos["pais_codigo"] === "") {
            $errores[] = "Marcá en el mapa dónde vivís.";
        } elseif ($datos["pais_id"] === null) {
            $errores[] = "Todavía no tenemos ese país en el juego. Elegí otro punto del mapa.";
        } elseif (mb_strlen($datos["ciudad"]) > 100 || mb_strlen($datos["provincia"]) > 100) {
            $errores[] = "El nombre de la ciudad o la provincia es demasiado largo.";
        }

        if (!filter_var($datos["email"], FILTER_VALIDATE_EMAIL) || mb_strlen($datos["email"]) > 120) {
            $errores[] = "El mail no es válido.";
        } elseif ($this->usuarioModel->existeEmail($datos["email"])) {
            $errores[] = "Ya hay una cuenta con ese mail.";
        }

        if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $datos["usuario"])) {
            $errores[] = "El usuario tiene que tener de 3 a 30 caracteres: letras, números, punto o guion bajo.";
        } elseif ($this->usuarioModel->existeUsuario($datos["usuario"])) {
            $errores[] = "Ese nombre de usuario ya está en uso.";
        }

        if (strlen($datos["password"]) < 8) {
            $errores[] = "La contraseña tiene que tener al menos 8 caracteres.";
        } elseif ($datos["password"] !== $datos["password2"]) {
            $errores[] = "Las contraseñas no coinciden.";
        }

        return $errores;
    }

    // Un número entre -$limite y $limite (latitud: 90, longitud: 180).
    private function esCoordenada($valor, $limite)
    {
        return is_numeric($valor) && abs((float)$valor) <= $limite;
    }

    // Arma los datos del formulario. Si hubo errores, vuelve con lo que ya se había cargado
    // (menos las contraseñas y la foto, que el navegador no permite volver a completar).
    private function mostrarFormulario(array $errores, array $datos)
    {
        $anioActual = (int)date("Y");

        $data["errores"] = $errores;
        $data["nombre_completo"] = $datos["nombre_completo"] ?? "";
        $data["anio_nacimiento"] = $datos["anio_nacimiento"] ?? "";
        $data["email"] = $datos["email"] ?? "";
        $data["usuario"] = $datos["usuario"] ?? "";
        $data["anio_minimo"] = $anioActual - 120;
        $data["anio_maximo"] = $anioActual - self::EDAD_MINIMA;

        $data["sexos"] = [];
        foreach (self::SEXOS as $valor => $texto) {
            $data["sexos"][] = [
                "valor" => $valor,
                "texto" => $texto,
                "elegido" => ($datos["sexo"] ?? "") === $valor,
            ];
        }

        // Si vuelve con errores, el mapa arranca con el punto que ya había elegido.
        foreach (["latitud", "longitud", "ciudad", "provincia", "pais_codigo", "pais_nombre"] as $campo) {
            $data[$campo] = $datos[$campo] ?? "";
        }
        $data["ubicacion_texto"] = implode(", ", array_filter([$data["ciudad"], $data["provincia"], $data["pais_nombre"]]));
        $data["usaMapa"] = true;

        $this->render->renderiza("registro", $data);
    }
}
