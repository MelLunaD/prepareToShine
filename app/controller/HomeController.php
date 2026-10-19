<?php

namespace app\controller;

class HomeController
{
    private $render;

    public function __construct($render)
    {
        $this->render = $render;
    }

    public function show()
    {
        $data["titulo"] = "Prepare to Shine";
        $data["usuario"] = $_SESSION['usuario'] ?? null;

        $this->render->renderiza("home", $data);
    }
}
