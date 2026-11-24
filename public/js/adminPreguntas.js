// Panel del administrador, pestaña Preguntas: dibuja los gráficos con Chart.js y cambia
// entre Shine y Die sin recargar la página (AJAX), igual que el filtro de período de Estadísticas.
(function () {
    var datosIniciales = document.getElementById("admin-preguntas-datos");
    if (!datosIniciales || typeof Chart === "undefined") {
        return;
    }

    // ---------- 1. Colores y estilo: los del panel (variables de estilos.css) ----------

    var css = getComputedStyle(document.body);
    function color(variable, otro) {
        return css.getPropertyValue(variable).trim() || otro;
    }
    var texto = color("--texto-suave", "#98a1b3");
    var grilla = "rgba(255, 255, 255, 0.08)";
    // Los mismos colores de las dificultades del formulario del editor: verde, amarillo y rojo.
    var colores = {
        facil: color("--verde", "#3fb950"),
        media: color("--moneda", "#f5c04a"),
        dificil: color("--rojo", "#e5484d")
    };
    var azul = "#5b9cf5";
    var gris = "#6b7385";

    Chart.defaults.color = texto;
    Chart.defaults.font.family = "Nunito, sans-serif";
    Chart.defaults.font.size = 13;
    Chart.defaults.borderColor = grilla;
    Chart.defaults.maintainAspectRatio = false;

    var leyenda = { display: true, position: "bottom", labels: { boxWidth: 12, padding: 14 } };

    function eje(titulo) {
        return { display: !!titulo, text: titulo || "", color: texto };
    }

    // ---------- 2. Crear los tres gráficos ----------

    var datos = JSON.parse(datosIniciales.textContent);

    var graficos = {
        // Barras apiladas: cada categoría es una barra y se ve cuántas son de cada dificultad.
        categorias: new Chart(document.getElementById("grafico-categorias"), {
            type: "bar",
            data: datosDeCategorias(datos.categorias),
            options: {
                plugins: { legend: leyenda },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: eje("Preguntas") }
                }
            }
        }),
        // Barras acostadas de 0 a 100%: los nombres de las categorías se leen enteros.
        acierto: new Chart(document.getElementById("grafico-acierto"), {
            type: "bar",
            data: datosDeAcierto(datos.categorias),
            options: {
                indexAxis: "y",
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (item) {
                                return item.raw + "% de " + datos.categorias.respondidas[item.dataIndex] + " respuestas";
                            }
                        }
                    }
                },
                scales: {
                    x: { min: 0, max: 100, ticks: { callback: function (v) { return v + "%"; } }, title: eje("Respuestas correctas") },
                    y: { grid: { display: false } }
                }
            }
        }),
        // Dos barras por dificultad: con cuál se cargaron y cuál calcula hoy el juego.
        dificultad: new Chart(document.getElementById("grafico-dificultad"), {
            type: "bar",
            data: datosDeDificultad(datos.dificultades),
            options: {
                plugins: { legend: leyenda },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, title: eje("Preguntas activas") }
                }
            }
        })
    };

    function datosDeCategorias(categorias) {
        return {
            labels: categorias.etiquetas,
            datasets: [
                { label: "Fácil", data: categorias.facil, backgroundColor: colores.facil, maxBarThickness: 56 },
                { label: "Media", data: categorias.media, backgroundColor: colores.media, maxBarThickness: 56 },
                { label: "Difícil", data: categorias.dificil, backgroundColor: colores.dificil, maxBarThickness: 56 }
            ]
        };
    }

    function datosDeAcierto(categorias) {
        return {
            labels: categorias.etiquetas,
            datasets: [{ data: categorias.porcentajes, backgroundColor: azul, borderRadius: 4, maxBarThickness: 32 }]
        };
    }

    function datosDeDificultad(dificultades) {
        return {
            labels: dificultades.etiquetas,
            datasets: [
                { label: "Cargada", data: dificultades.inicial, backgroundColor: gris, borderRadius: 4, maxBarThickness: 40 },
                {
                    label: "Calculada hoy",
                    data: dificultades.hoy,
                    backgroundColor: [colores.facil, colores.media, colores.dificil],
                    borderRadius: 4,
                    maxBarThickness: 40
                }
            ]
        };
    }

    // Cambia los datos de los gráficos que ya existen y los anima hacia los nuevos valores.
    function actualizar(nuevos) {
        datos = nuevos;
        graficos.categorias.data = datosDeCategorias(nuevos.categorias);
        graficos.acierto.data = datosDeAcierto(nuevos.categorias);
        graficos.dificultad.data = datosDeDificultad(nuevos.dificultades);
        // El texto lo arma el servidor; acá solo se cambia el del otro modo.
        document.getElementById("nota-dificultad").textContent = nuevos.notaDificultad;
        Object.keys(graficos).forEach(function (nombre) {
            graficos[nombre].update();
        });
    }

    // ---------- 3. Shine / Die por AJAX ----------

    var tarjetas = document.getElementById("admin-preguntas-tarjetas");
    var tablas = document.getElementById("admin-preguntas-tablas");
    var links = document.querySelectorAll(".admin-modos .admin-periodo");
    var pedidoEnCurso = null;

    function marcarActivo(modo) {
        links.forEach(function (link) {
            var activo = link.dataset.modo === modo;
            link.classList.toggle("activo", activo);
            if (activo) {
                link.setAttribute("aria-current", "true");
            } else {
                link.removeAttribute("aria-current");
            }
        });
    }

    function cargar(modo, guardarEnHistorial) {
        if (pedidoEnCurso) {
            pedidoEnCurso.abort();
        }
        pedidoEnCurso = new AbortController();
        marcarActivo(modo);
        document.body.classList.add("admin-cargando");

        fetch("/admin/preguntasDatos?modo=" + encodeURIComponent(modo), { signal: pedidoEnCurso.signal })
            .then(function (respuesta) {
                // Sesión vencida: el Router redirige al login y recargamos la página entera.
                if (!respuesta.ok || respuesta.redirected) {
                    throw new Error("respuesta inesperada");
                }
                return respuesta.json();
            })
            .then(function (json) {
                // El HTML viene armado con Mustache desde el servidor: solo se pone en su lugar.
                tarjetas.innerHTML = json.tarjetas;
                tablas.innerHTML = json.tablas;
                actualizar(json.graficos);
                // "Nueva pregunta" pasa a crear en el modo nuevo.
                var nueva = document.getElementById("admin-nueva");
                if (nueva) {
                    nueva.href = "/admin/nueva?modo=" + json.modo;
                }
                // El buscador pasa a buscar en el modo nuevo (y vuelve a buscar si ya había texto).
                var buscador = document.querySelector(".admin-buscador");
                if (buscador) {
                    buscador.setAttribute("data-fase", json.modo);
                    buscador.dispatchEvent(new Event("buscador:buscar"));
                }
                document.body.classList.remove("admin-cargando");
                pedidoEnCurso = null;

                if (guardarEnHistorial) {
                    history.pushState({ modo: json.modo }, "", "/admin/preguntas?modo=" + json.modo);
                }
            })
            .catch(function (error) {
                if (error.name === "AbortError") {
                    return;
                }
                window.location.href = "/admin/preguntas?modo=" + encodeURIComponent(modo);
            });
    }

    links.forEach(function (link) {
        link.addEventListener("click", function (evento) {
            evento.preventDefault();
            cargar(link.dataset.modo, true);
        });
    });

    // Botones atrás/adelante del navegador: vuelve al modo de esa entrada del historial.
    window.addEventListener("popstate", function () {
        var modo = new URLSearchParams(location.search).get("modo") || "shine";
        cargar(modo, false);
    });
})();
