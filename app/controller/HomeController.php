<?php

namespace app\controller;

use Sesion;

class HomeController
{
    private $render;

    public function __construct($render)
    {
        $this->render = $render;
    }

    public function show()
    {
        // Fase visual: "shine" (colorida) o "souls" (oscura). La home arranca en shine;
        // el paso a souls lo dispara el secreto de public/js/secreto.js.
        $data["tema"] = "shine";
        $data["palabraFase"] = $data["tema"] === "souls" ? "Die" : "Shine";
        $data["sesion"] = Sesion::usuario();

        $this->render->renderiza("home", $data);
    }
}
