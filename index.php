<?php
use app\Configuration;

// Cookie de sesión inaccesible desde JavaScript y que no viaja en pedidos de otros sitios.
session_set_cookie_params(["httponly" => true, "samesite" => "Lax"]);
session_start();
require_once("app/Configuration.php");

$configuration = new Configuration();
$router = $configuration->getRouter();

$router->dispatch(
    Request::get("controller", "home"),
    Request::get("method", "show")
);
