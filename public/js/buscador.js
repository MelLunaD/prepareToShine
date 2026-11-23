// Buscador de preguntas parecidas, en cada tarjeta de la pestaña Sugeridas (solo para mirar).
// Pide GET /editor/buscar?q=...&excluir=id con fetch (AJAX) y arma la lista con el JSON.
// Para no hacer un pedido por cada letra: espera 3 letras y 0,4 s sin tipear (debounce).
(function () {
    var ESPERA_MS = 400;
    var MINIMO_LETRAS = 3;
    var buscadores = document.querySelectorAll("[data-buscador]");

    // En pantallas grandes el buscador va al costado y arranca abierto; en celular, plegado.
    var pantallaGrande = window.matchMedia("(min-width: 900px)").matches;

    buscadores.forEach(function (buscador) {
        var campo = buscador.querySelector("[data-buscador-texto]");
        var estado = buscador.querySelector("[data-buscador-estado]");
        var lista = buscador.querySelector("[data-buscador-lista]");
        var cantidad = buscador.querySelector("[data-buscador-cantidad]");
        var excluir = buscador.getAttribute("data-excluir");
        var fase = buscador.getAttribute("data-fase"); // solo busca en el mismo modo (shine o die)
        var temporizador = null;
        var pedidoActual = null;

        if (pantallaGrande) {
            buscador.open = true;
        }

        function mostrar(resultados) {
            lista.innerHTML = "";
            cantidad.textContent = "(" + resultados.length + ")";
            estado.textContent = resultados.length === 0
                ? "No hay preguntas parecidas."
                : resultados.length + " parecida" + (resultados.length === 1 ? "" : "s") + ", la más parecida primero.";

            resultados.forEach(function (r) {
                var item = document.createElement("li");
                item.className = "buscador-resultado estado-" + r.estado;

                var texto = document.createElement("p");
                texto.className = "buscador-texto";
                texto.textContent = r.texto; // textContent: el texto nunca se interpreta como HTML

                var dato = document.createElement("p");
                dato.className = "buscador-dato";
                dato.textContent = "#" + r.id + " · " + r.categoria + " · " +
                    r.coincidencias + "/" + r.palabras + " palabras";

                var etiqueta = document.createElement("span");
                etiqueta.className = "buscador-estado";
                etiqueta.textContent = r.estadoNombre;

                item.appendChild(etiqueta);
                item.appendChild(texto);
                item.appendChild(dato);
                lista.appendChild(item);
            });
        }

        function buscar() {
            var texto = campo.value.trim();
            if (texto.length < MINIMO_LETRAS) {
                lista.innerHTML = "";
                cantidad.textContent = "";
                estado.textContent = "Escribí al menos " + MINIMO_LETRAS + " letras.";
                return;
            }

            // Si el editor sigue escribiendo, el pedido anterior ya no sirve: se cancela.
            if (pedidoActual) {
                pedidoActual.abort();
            }
            pedidoActual = new AbortController();
            estado.textContent = "Buscando...";

            var url = "/editor/buscar?q=" + encodeURIComponent(texto) + "&excluir=" + encodeURIComponent(excluir) +
                "&fase=" + encodeURIComponent(fase);
            fetch(url, { headers: { "Accept": "application/json" }, signal: pedidoActual.signal })
                .then(function (respuesta) {
                    return respuesta.json();
                })
                .then(function (datos) {
                    mostrar(datos.resultados);
                })
                .catch(function (error) {
                    if (error.name !== "AbortError") {
                        estado.textContent = "No se pudo buscar. Probá de nuevo.";
                    }
                });
        }

        campo.addEventListener("input", function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, ESPERA_MS);
        });

        // Arranca buscando con el texto de la sugerida: así se ven los posibles duplicados de entrada.
        buscar();
    });
})();
