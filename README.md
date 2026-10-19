# Prepare to Shine

TP Final de Programación Web 2: juego de preguntas y respuestas.
Construido sobre el MVC de la cátedra (PHP + Mustache).

## Cómo levantarlo

1. Copiar el proyecto en la raíz del servidor Apache (con `mod_rewrite` habilitado).
   Si queda en una subcarpeta, cambiar `RewriteBase` en `.htaccess`.
2. Copiar `config/config.example.ini` como `config/config.ini` y completar los datos de la BD.
3. Entrar a `http://localhost/`.

## Estructura

```
index.php              Front controller: arma Configuration y despacha /controller/method
.htaccess              URLs limpias y bloqueo de carpetas internas
config/                Configuración local (config.ini no se versiona)
app/Configuration.php  Arma cada controller con sus dependencias
app/controller/        Controllers
app/model/             Models (acceso a la BD)
app/view/              Vistas .mustache + header.php / footer.php
app/sql/               Scripts de la base de datos
helper/                Router, Autoloader, MyDatabase, MustacheRender, Request, Redirect, Log
vendor/mustache/       Motor de plantillas Mustache
public/                Archivos estáticos (css, js, imágenes)
log/                   Logs diarios (no se versionan)
```

Para agregar una pantalla: crear Controller + Model + View y sumar su `get{Nombre}Controller()` en `app/Configuration.php`.

El login base (`LoginController`, `LoginModel`, `loginView`) está incluido pero desconectado hasta definir la base de datos.
