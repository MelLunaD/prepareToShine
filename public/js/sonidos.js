// Sonidos del juego (8 bits, sintetizados por el equipo; los archivos están en public/sonidos/).
//
// Hay dos grupos que se prenden y apagan por separado:
//   - "musica": los loops de fondo de cada modo.
//   - "efectos": todo lo demás (clics, respuestas, timer, ganar, perder...).
// La elección se guarda en localStorage, así no hay que volver a silenciar en cada página.
//
// Desde otros scripts se usa así:  Sonido.reproducir("correcta");
// Los nombres de la lista COMUNES suenan igual en los dos modos; los demás tienen versión Shine
// y Die (public/sonidos/shine-correcta.mp3, die-correcta.mp3...) y se elige según el tema.
// Una vista también puede pedir sonidos al abrirse con <div hidden data-sonidos="ganar subir-rango">.
var Sonido = (function () {
    var CARPETA = "/public/sonidos/";
    var CLAVES = { musica: "ptd-sonido-musica", efectos: "ptd-sonido-efectos" };
    var COMUNES = ["clic", "carta", "golpe", "cambio", "mezclar", "hover", "respuesta", "ranking"];
    var cargados = {};

    // localStorage puede fallar (modo privado, bloqueado): en ese caso queda todo prendido.
    function leer(grupo) {
        try {
            return localStorage.getItem(CLAVES[grupo]) !== "0";
        } catch (e) {
            return true;
        }
    }

    function guardar(grupo, prendido) {
        try {
            localStorage.setItem(CLAVES[grupo], prendido ? "1" : "0");
        } catch (e) {
            // sin localStorage la elección dura hasta cambiar de página
        }
    }

    var prendido = { musica: leer("musica"), efectos: leer("efectos") };

    // "shine" o "die", según el tema que eligió el controller para la página.
    function modo() {
        return document.body.classList.contains("tema-souls") ? "die" : "shine";
    }

    function archivo(nombre) {
        return COMUNES.indexOf(nombre) === -1 ? modo() + "-" + nombre : nombre;
    }

    // ---- Reproducir ----
    // Se usa la Web Audio API: cada sonido se descarga y se decodifica UNA vez (al abrir la página)
    // y después suena al instante. Con <audio> había demora en el primer clic y a veces no
    // llegaba a sonar antes de cambiar de página. Si el navegador no la tiene, se usa <audio>.
    var Contexto = window.AudioContext || window.webkitAudioContext;
    var contexto = Contexto ? new Contexto() : null;
    var buffers = {};

    // El navegador arranca el audio "suspendido" hasta que el usuario toca algo: se despierta
    // en el primer toque o tecla.
    function despertar() {
        if (contexto && contexto.state === "suspended") {
            contexto.resume().then(arrancarMusica);
        }
    }
    document.addEventListener("pointerdown", despertar, true);
    document.addEventListener("keydown", despertar, true);

    function cargar(ruta) {
        if (!buffers[ruta]) {
            buffers[ruta] = fetch(ruta)
                .then(function (respuesta) { return respuesta.arrayBuffer(); })
                .then(function (datos) {
                    // Forma con callbacks: la entienden también los Safari viejos.
                    return new Promise(function (ok, mal) { contexto.decodeAudioData(datos, ok, mal); });
                });
            buffers[ruta].catch(function () { delete buffers[ruta]; });
        }
        return buffers[ruta];
    }

    // Deja descargados y listos los sonidos que una página va a usar.
    function precargar(nombres) {
        if (!contexto) {
            return;
        }
        nombres.forEach(function (nombre) { cargar(CARPETA + archivo(nombre) + ".mp3"); });
    }

    // Reproduce un efecto si los efectos están prendidos.
    // opciones: { volumen: 0 a 1, velocidad: 1 = normal (menos de 1 = más lento y más grave),
    //             alTerminar: función que se llama cuando termina }
    function reproducir(nombre, opciones) {
        opciones = opciones || {};
        if (!prendido.efectos) {
            return;
        }
        var ruta = CARPETA + archivo(nombre) + ".mp3";
        var volumen = opciones.volumen || 0.9;

        if (!contexto) {
            reproducirConAudio(ruta, volumen, opciones);
            return;
        }
        // resume() devuelve una promesa: si el navegador no deja sonar todavía, queda "suspended".
        var listo = contexto.state === "running" ? Promise.resolve() : contexto.resume();
        Promise.all([cargar(ruta), listo]).then(function (resultado) {
            var buffer = resultado[0];
            // Si el navegador todavía no dejó sonar (nadie tocó la página), se saltea.
            if (contexto.state !== "running") {
                return;
            }
            var fuente = contexto.createBufferSource();
            var ganancia = contexto.createGain();
            fuente.buffer = buffer;
            fuente.playbackRate.value = opciones.velocidad || 1;
            ganancia.gain.value = volumen;
            fuente.connect(ganancia).connect(contexto.destination);
            if (opciones.alTerminar) {
                fuente.onended = opciones.alTerminar;
            }
            fuente.start();
        }).catch(function () {
            // No se pudo cargar: se sigue con lo que venga después (ver enOrden).
            if (opciones.alTerminar) {
                opciones.alTerminar();
            }
        });
    }

    function reproducirConAudio(ruta, volumen, opciones) {
        var audio = new Audio(ruta);
        audio.volume = volumen;
        if (opciones.velocidad) {
            // Sin esto el navegador cambia la velocidad pero mantiene el tono.
            audio.preservesPitch = audio.mozPreservesPitch = audio.webkitPreservesPitch = false;
            audio.playbackRate = opciones.velocidad;
        }
        if (opciones.alTerminar) {
            audio.addEventListener("ended", opciones.alTerminar);
        }
        var promesa = audio.play();
        if (promesa && promesa.catch) {
            promesa.catch(function () {});
        }
    }

    // Pinta todos los botones de un grupo (los de la esquina y los del menú) según su estado.
    function actualizarBotones() {
        document.querySelectorAll("[data-sonido-toggle]").forEach(function (boton) {
            var activo = prendido[boton.dataset.sonidoToggle];
            boton.classList.toggle("apagado", !activo);
            boton.setAttribute("aria-pressed", activo ? "true" : "false");
            var estado = boton.querySelector("[data-sonido-estado]");
            if (estado) {
                estado.textContent = activo ? "Sí" : "No";
            }
        });
    }

    function cambiar(grupo) {
        prendido[grupo] = !prendido[grupo];
        guardar(grupo, prendido[grupo]);
        actualizarBotones();
        document.dispatchEvent(new CustomEvent("sonido:cambio", { detail: { grupo: grupo, prendido: prendido[grupo] } }));
        if (grupo === "musica") {
            if (prendido.musica) {
                arrancarMusica();
            } else {
                pararMusica();
            }
        }
        if (grupo === "efectos" && prendido.efectos) {
            reproducir("clic");
        }
    }

    // ---- Clic en los botones principales ----
    // Los botones .btn, el menú y sus links. Las opciones de respuesta no: tienen su propio sonido.
    var CON_CLIC = ".btn:not(.opcion-respuesta), .hud-menu-boton, .hud-menu-lista a, [data-sonido-clic]";
    // Un link o un formulario cambia de página enseguida y corta el sonido: se espera un poquito.
    var ESPERA_MS = 90;

    // El clic suena al apoyar el dedo o apretar el mouse (pointerdown), que llega antes que el
    // "click": así se escucha enseguida. Con el teclado (Enter) no hay pointerdown: suena en el click.
    function alApoyar(evento) {
        var elemento = evento.target.closest(CON_CLIC);
        if (elemento && !elemento.disabled && evento.button === 0) {
            reproducir("clic");
        }
    }

    function alHacerClic(evento) {
        var toggle = evento.target.closest("[data-sonido-toggle]");
        if (toggle) {
            cambiar(toggle.dataset.sonidoToggle);
            return;
        }

        var elemento = evento.target.closest(CON_CLIC);
        if (!elemento || elemento.disabled || !prendido.efectos) {
            return;
        }
        if (evento.detail === 0) {
            reproducir("clic");
        }

        // Ctrl/Cmd/Shift + clic o target="_blank" abren otra pestaña: esta página no se va.
        if (evento.defaultPrevented || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.button !== 0) {
            return;
        }
        if (elemento.tagName === "A" && elemento.href && !elemento.target && elemento.getAttribute("href").charAt(0) !== "#") {
            evento.preventDefault();
            setTimeout(function () {
                window.location.href = elemento.href;
            }, ESPERA_MS);
        } else if (elemento.tagName === "BUTTON" && elemento.type === "submit" && elemento.form && !elemento.form.target) {
            evento.preventDefault();
            setTimeout(function () {
                // requestSubmit manda el valor del botón (name/value) y respeta las validaciones.
                elemento.form.requestSubmit(elemento);
            }, ESPERA_MS);
        }
    }

    // ---- Música de fondo ----
    // Suena en las páginas que la piden (<div hidden data-musica>: lobby y partida), en loop y
    // bajita. Al cambiar de página sigue desde donde iba (se guarda en sessionStorage), así no
    // vuelve a empezar en cada pregunta.
    var LOOPS = { shine: 12.8, die: 25.263 };   // duración exacta de cada loop, en segundos
    var VOLUMEN_MUSICA = 0.4;
    var CLAVE_POSICION = "ptd-musica-posicion";
    var hayMusica = !!document.querySelector("[data-musica]");
    var musica = null;   // { fuente, ganancia, modo, inicio, largo } mientras suena

    // El mp3 puede traer unos milisegundos de silencio al principio: el loop arranca después.
    function comienzo(buffer) {
        var datos = buffer.getChannelData(0);
        var limite = Math.min(datos.length, Math.floor(buffer.sampleRate * 0.1));
        for (var i = 0; i < limite; i++) {
            if (Math.abs(datos[i]) > 0.001) {
                return i / buffer.sampleRate;
            }
        }
        return 0;
    }

    function posicionGuardada(modoMusica) {
        try {
            var guardada = JSON.parse(sessionStorage.getItem(CLAVE_POSICION));
            return guardada && guardada.modo === modoMusica ? guardada.posicion : 0;
        } catch (e) {
            return 0;
        }
    }

    function guardarPosicion() {
        if (!musica) {
            return;
        }
        try {
            var posicion = (contexto.currentTime - musica.inicio) % musica.largo;
            sessionStorage.setItem(CLAVE_POSICION, JSON.stringify({ modo: musica.modo, posicion: posicion }));
        } catch (e) {
            // sin sessionStorage la música arranca de cero en cada página
        }
    }
    window.addEventListener("pagehide", guardarPosicion);

    function arrancarMusica() {
        if (!contexto || !hayMusica || !prendido.musica || musica) {
            return;
        }
        var modoMusica = modo();
        var listo = contexto.state === "running" ? Promise.resolve() : contexto.resume();
        Promise.all([cargar(CARPETA + modoMusica + "-musica.mp3"), listo]).then(function (resultado) {
            // Puede que el navegador no deje sonar todavía: arranca en el primer toque (despertar).
            if (contexto.state !== "running" || musica || !prendido.musica) {
                return;
            }
            var buffer = resultado[0];
            var desde = comienzo(buffer);
            var largo = Math.min(LOOPS[modoMusica], buffer.duration - desde);
            var posicion = posicionGuardada(modoMusica) % largo;

            var fuente = contexto.createBufferSource();
            var ganancia = contexto.createGain();
            fuente.buffer = buffer;
            fuente.loop = true;
            fuente.loopStart = desde;
            fuente.loopEnd = desde + largo;
            fuente.connect(ganancia).connect(contexto.destination);
            // Entra enseguida (al cambiar de página casi no se nota el corte); la rampa de 30 ms
            // solo evita el "tac" de arrancar a mitad de una nota.
            ganancia.gain.setValueAtTime(0, contexto.currentTime);
            ganancia.gain.linearRampToValueAtTime(VOLUMEN_MUSICA, contexto.currentTime + 0.03);
            fuente.start(0, desde + posicion);

            musica = { fuente: fuente, ganancia: ganancia, modo: modoMusica, inicio: contexto.currentTime - posicion, largo: largo };
        }).catch(function () {});
    }

    // Deja descargada la música de un modo antes de que haga falta (el secreto de la portada
    // precarga la de Die para que entre justo al terminar el cambio).
    function precargarMusica(modoMusica) {
        if (contexto) {
            cargar(CARPETA + modoMusica + "-musica.mp3");
        }
    }

    function pararMusica() {
        if (!musica) {
            return;
        }
        guardarPosicion();
        var actual = musica;
        musica = null;
        actual.ganancia.gain.cancelScheduledValues(contexto.currentTime);
        actual.ganancia.gain.setValueAtTime(actual.ganancia.gain.value, contexto.currentTime);
        actual.ganancia.gain.linearRampToValueAtTime(0, contexto.currentTime + 0.4);
        actual.fuente.stop(contexto.currentTime + 0.45);
    }

    // Mientras suena algo importante (hoguera, correcta...) la música baja y después vuelve.
    function bajarMusica(bajar) {
        if (!musica) {
            return;
        }
        var g = musica.ganancia.gain;
        g.cancelScheduledValues(contexto.currentTime);
        g.setValueAtTime(g.value, contexto.currentTime);
        g.linearRampToValueAtTime(bajar ? VOLUMEN_MUSICA * 0.3 : VOLUMEN_MUSICA, contexto.currentTime + (bajar ? 0.2 : 0.8));
    }

    // Cambia a la música del tema actual (lo usa el secreto de la portada al pasar a Die).
    function cambiarMusica() {
        pararMusica();
        arrancarMusica();
    }

    // Varios sonidos seguidos: cada uno arranca cuando termina el anterior.
    function enOrden(nombres) {
        if (nombres.length === 0) {
            bajarMusica(false);
            return;
        }
        if (prendido.efectos) {
            bajarMusica(true);
        }
        reproducir(nombres[0], {
            alTerminar: function () {
                setTimeout(function () { enOrden(nombres.slice(1)); }, 150);
            }
        });
    }

    actualizarBotones();
    document.addEventListener("pointerdown", alApoyar);
    document.addEventListener("click", alHacerClic);

    precargar(["clic"]);
    var alAbrir = document.querySelector("[data-sonidos]");
    if (alAbrir) {
        var lista = alAbrir.dataset.sonidos.split(" ").filter(Boolean);
        precargar(lista);
        enOrden(lista);
    }
    arrancarMusica();

    return {
        reproducir: reproducir,
        precargar: precargar,
        enOrden: enOrden,
        pararMusica: pararMusica,
        cambiarMusica: cambiarMusica,
        precargarMusica: precargarMusica,
        modo: modo,
        prendido: function (grupo) {
            return prendido[grupo];
        }
    };
})();
