// Ranking: miniperfiles y cambio de pestañas y filtros sin recargar la página (AJAX).
// Explicación paso por paso: doc "AJAX en el ranking, paso por paso" (https://claude.ai/artifact/Kj7SUWJgr8SByAK9rJseav).
(function () {
    var contenido = document.getElementById("ranking-contenido");
    if (!contenido) {
        return;
    }

    // Con JS los selects filtran solos al cambiar: el botón "Filtrar" ya no hace falta.
    // (Sin JS el botón queda y el formulario recarga la página, como siempre).
    document.body.classList.add("ranking-con-js");

    // Para cancelar un pedido viejo si se cambia de filtro antes de que llegue la respuesta.
    var pedidoEnCurso = null;

    // ---------- 1. Pedir la lista al servidor y ponerla en la página ----------

    // query: "modo=shine&periodo=semana&tipo=puntos&pais=AR", sin el "?" adelante.
    // guardarEnHistorial: false cuando venimos de los botones atrás/adelante del navegador.
    function cargar(query, guardarEnHistorial) {
        if (pedidoEnCurso) {
            pedidoEnCurso.abort();
        }
        pedidoEnCurso = new AbortController();
        contenido.classList.add("cargando");

        // fetch() pide /ranking/lista sin salir de la página. El navegador manda la cookie
        // de sesión como en cualquier pedido, así que el Router sabe quién está logueado.
        fetch("/ranking/lista?" + query, { signal: pedidoEnCurso.signal })
            .then(function (respuesta) {
                // Si la sesión venció, el Router redirige al login: ahí recargamos todo.
                if (!respuesta.ok || respuesta.redirected) {
                    throw new Error("respuesta inesperada");
                }
                return respuesta.text();
            })
            .then(function (html) {
                // El servidor ya mandó el HTML armado con Mustache: solo hay que ponerlo.
                contenido.innerHTML = html;
                contenido.classList.remove("cargando");
                pedidoEnCurso = null;

                // La URL de la barra cambia a /ranking?... sin recargar: así recargar,
                // compartir el link o volver atrás muestran este mismo ranking.
                if (guardarEnHistorial) {
                    history.pushState(null, "", "/ranking?" + query);
                }
            })
            .catch(function (error) {
                if (error.name === "AbortError") {
                    return; // lo cancelamos nosotros: ya hay otro pedido en camino
                }
                // Cualquier otro problema (sin conexión, sesión vencida): se recarga normal.
                window.location.href = "/ranking?" + query;
            });
    }

    // Arma "modo=shine&periodo=semana&..." con los campos del formulario, sin los vacíos
    // ("Todos" o "Todas" en un filtro = no filtrar).
    function queryDelFormulario(formulario) {
        var parametros = new URLSearchParams();
        new FormData(formulario).forEach(function (valor, nombre) {
            if (valor !== "") {
                parametros.append(nombre, valor);
            }
        });
        return parametros.toString();
    }

    // ---------- 2. Escuchar lo que toca el jugador ----------
    // Los listeners van en "contenido" (que no se reemplaza) y no en cada link o select (que sí
    // se reemplazan con cada respuesta): el evento "sube" desde lo tocado hasta acá.

    contenido.addEventListener("click", function (evento) {
        // Pestañas (Semanal, Histórico, Puntos...) y "Sacar filtros": ya traen su URL.
        var link = evento.target.closest("a.pestania, a.ranking-limpiar");
        if (!link) {
            return;
        }
        evento.preventDefault(); // que el link no recargue la página
        cargar(link.search.slice(1), true);
    });

    contenido.addEventListener("change", function (evento) {
        if (evento.target.matches(".ranking-filtros select")) {
            cargar(queryDelFormulario(evento.target.form), true);
        }
    });

    contenido.addEventListener("submit", function (evento) {
        if (evento.target.matches(".ranking-filtros")) {
            evento.preventDefault();
            cargar(queryDelFormulario(evento.target), true);
        }
    });

    // Botones atrás/adelante del navegador: la URL ya cambió, se pide la lista de esa URL.
    window.addEventListener("popstate", function () {
        cargar(window.location.search.slice(1), false);
    });

    // ---------- 3. Miniperfil ----------
    // Al tocar el nombre de un jugador se abre su <dialog> (ya viene en la página: no pide nada
    // al servidor). Si el navegador no soporta <dialog>, el link lleva directo al perfil.

    document.addEventListener("click", function (evento) {
        var link = evento.target.closest("[data-miniperfil]");
        if (!link) {
            return;
        }
        var dialogo = document.getElementById(link.dataset.miniperfil);
        if (!dialogo || typeof dialogo.showModal !== "function") {
            return;
        }
        evento.preventDefault();

        // El QR (imagen de api.qrserver.com) se carga solo la primera vez que se abre.
        var qr = dialogo.querySelector("img[data-src]");
        if (qr) {
            qr.src = qr.dataset.src;
            qr.removeAttribute("data-src");
        }

        dialogo.showModal();
    });

    // Tocar afuera de la tarjeta (el fondo oscuro) también la cierra.
    document.addEventListener("click", function (evento) {
        if (evento.target.matches("dialog.miniperfil")) {
            evento.target.close();
        }
    });
})();
