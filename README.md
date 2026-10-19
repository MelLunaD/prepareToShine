# Prepare to Shine

TP Final de Programación Web 2: juego de preguntas y respuestas.
Construido sobre el MVC de la cátedra (PHP + Mustache).

## Cómo levantarlo

1. Copiar el proyecto en la raíz del servidor Apache (con `mod_rewrite` habilitado).
   Si queda en una subcarpeta, cambiar `RewriteBase` en `.htaccess`.
2. Crear una base vacía (por ejemplo `prepare_to_shine`, en utf8mb4) e importar en orden los scripts de `app/sql/` (ver abajo).
3. Copiar `config/config.example.ini` como `config/config.ini` y completar los datos de la BD.
4. Entrar a `http://localhost/`.

## Base de datos

Los scripts están en `app/sql/` y se importan en este orden:

1. `01-esquema.sql`: crea las tablas. Si ya existen, las borra y las vuelve a crear (se pierden los datos).
2. `02-datos-iniciales.sql`: países, las 10 categorías y el usuario `sistema`, autor de las preguntas iniciales (no puede iniciar sesión).
3. `03-preguntas.sql`: las 1500 preguntas iniciales, 250 por cada tipo (fácil, media y difícil de Shine y de Die), con sus 4 opciones.

`03-preguntas.sql` se genera desde `app/sql/preguntas.csv`. Para corregir o sumar preguntas se edita el CSV y se corre `php app/sql/generar-preguntas.php`.

### En InfinityFree

1. En el panel de InfinityFree, entrar a **MySQL Databases** y crear la base. Anotar host, usuario, contraseña y nombre completo de la base (con el prefijo de la cuenta).
2. Abrir **phpMyAdmin** sobre esa base y, en **Importar**, subir los tres `.sql` en orden.
3. En GitHub, en **Settings > Secrets and variables > Actions**, cargar `DB_HOST`, `DB_USER`, `DB_PASS` y `DB_NAME` con esos datos (y los del mail, ver más abajo). El deploy arma `config/config.ini` con ellos.

`app/sql/` no se sube al hosting: la base se carga a mano por phpMyAdmin.

## Estructura

```
index.php              Front controller: arma Configuration y despacha /controller/method
.htaccess              URLs limpias y bloqueo de carpetas internas
config/                Configuración local (config.ini no se versiona)
app/Configuration.php  Arma cada controller con sus dependencias
app/controller/        Controllers
app/model/             Models (acceso a la BD)
app/view/              Vistas .mustache (header.mustache y footer.mustache incluidos)
app/sql/               Scripts de la base de datos y generador de preguntas
helper/                Router, Autoloader, MyDatabase, MustacheRender, Request, Redirect, Log, Sesion, Mailer
vendor/mustache/       Motor de plantillas Mustache
vendor/phpmailer/      PHPMailer 6.10 (envío de mails por SMTP)
public/                Archivos estáticos (css, js, imágenes)
log/                   Logs diarios (no se versionan)
```

Para agregar una pantalla: crear Controller + Model + View y sumar su `get{Nombre}Controller()` en `app/Configuration.php`.

## Login y registro

Sin sesión solo se puede entrar a `/login` y `/registro` (la lista está en `Configuration::CONTROLLERS_PUBLICOS` y la controla el `Router`); cualquier otra página manda al login.

1. `/registro` crea la cuenta sin validar y manda por mail un código de 6 números que vence a los 10 minutos.
2. `/registro/validar` pide el código. "Reenviar código" genera uno nuevo y el anterior deja de servir. Después de 5 intentos fallidos el código se anula.
3. Si la persona se va sin validar, la cuenta queda creada. Al loguearse se le manda un código nuevo y tiene que validarla antes de entrar.

Las contraseñas se guardan con `password_hash`. Las fotos de perfil se suben a `public/img/perfiles/` (no se versionan); sin foto se usa `public/img/avatar.svg`.

### Mail

Los mails salen por SMTP con PHPMailer (`vendor/phpmailer/`, envuelto en `helper/Mailer.php`), porque InfinityFree no permite `mail()`. Se usa la cuenta de Gmail del juego con una **contraseña de aplicación**:

1. En la cuenta de Google, activar la **Verificación en 2 pasos** (sin eso no aparece la opción de contraseñas de aplicación).
2. Crear una contraseña en <https://myaccount.google.com/apppasswords>.
3. En GitHub, cargar los secrets `MAIL_USER` (el mail) y `MAIL_PASS` (la contraseña de 16 letras, sin espacios). El deploy los escribe en `config/config.ini`.

En local, si `mail_user` queda vacío en `config.ini`, no se manda nada: el mail (con el código) se escribe en `log/`.
