// Panel del administrador, pestaña Estadísticas: dibuja los gráficos con Chart.js y cambia
// el período sin recargar la página (AJAX), igual que los filtros del ranking.
(function () {
    var datosIniciales = document.getElementById("admin-graficos-datos");
    if (!datosIniciales || typeof Chart === "undefined") {
        return;
    }

    // ---------- 1. Colores y estilo: los del panel (variables de estilos.css) ----------

    var css = getComputedStyle(document.body);
    function color(variable, otro) {
        return css.getPropertyValue(variable).trim() || otro;
    }
    var amarillo = color("--moneda", "#f5c04a");
    var texto = color("--texto-suave", "#9aa3b2");
    var grilla = "rgba(255, 255, 255, 0.08)";
    // Para los gráficos con varias porciones (sexo): colores bien distintos entre sí.
    var paleta = ["#f5c04a", "#5b9cf5", "#9aa3b2"];

    Chart.defaults.color = texto;
    Chart.defaults.font.family = "Nunito, sans-serif";
    Chart.defaults.font.size = 13;
    Chart.defaults.borderColor = grilla;
    Chart.defaults.plugins.legend.display = false;
    Chart.defaults.maintainAspectRatio = false;

    // Escala que solo muestra números enteros (no hay "media partida").
    // titulo: el nombre del eje ("Partidas", "Jugadores"...), para que se entienda qué se cuenta.
    function enteros(titulo) {
        return { beginAtZero: true, ticks: { precision: 0 }, grid: { color: grilla }, title: eje(titulo) };
    }
    function sinGrilla(titulo) {
        return { grid: { display: false }, title: eje(titulo) };
    }
    function eje(titulo) {
        return { display: !!titulo, text: titulo || "", color: texto };
    }

    // ---------- 2. Crear los cuatro gráficos ----------

    var datos = JSON.parse(datosIniciales.textContent);

    var graficos = {
        tiempo: new Chart(document.getElementById("grafico-tiempo"), {
            type: "bar",
            data: serie(datos.tiempo, amarillo),
            options: { scales: { y: enteros("Partidas"), x: sinGrilla(datos.tiempo.ejeX) } }
        }),
        pais: new Chart(document.getElementById("grafico-pais"), {
            type: "bar",
            data: serie(datos.pais, "#5b9cf5"),
            // Barras acostadas: los nombres de los países se leen enteros.
            options: { indexAxis: "y", scales: { x: enteros("Jugadores"), y: sinGrilla() } }
        }),
        sexo: new Chart(document.getElementById("grafico-sexo"), {
            type: "doughnut",
            data: serie(datos.sexo, paleta),
            options: {
                cutout: "60%",
                plugins: { legend: { display: true, position: "bottom", labels: { boxWidth: 12, padding: 14 } } }
            }
        }),
        edad: new Chart(document.getElementById("grafico-edad"), {
            type: "bar",
            data: serie(datos.edad, "#3fb950"),
            options: { scales: { y: enteros("Jugadores"), x: sinGrilla("Edad (años)") } }
        })
    };

    // {etiquetas: [...], valores: [...]} (lo que manda el servidor) -> formato de Chart.js.
    function serie(datosDelServidor, colores) {
        return {
            labels: datosDelServidor.etiquetas,
            datasets: [{
                data: datosDelServidor.valores,
                backgroundColor: colores,
                borderColor: Array.isArray(colores) ? color("--papel", "#1f232c") : colores,
                borderWidth: Array.isArray(colores) ? 2 : 0,
                borderRadius: Array.isArray(colores) ? 0 : 4,
                maxBarThickness: 48
            }]
        };
    }

    // Cambia los datos de un gráfico que ya existe y lo anima hacia los nuevos valores.
    function actualizar(grafico, datosDelServidor) {
        grafico.data.labels = datosDelServidor.etiquetas;
        grafico.data.datasets[0].data = datosDelServidor.valores;
        grafico.update();
    }

    // ---------- 3. Filtro de período por AJAX ----------

    var tarjetas = document.getElementById("admin-estadisticas");
    var aciertos = document.getElementById("admin-aciertos");
    var links = document.querySelectorAll(".admin-periodo");
    var pedidoEnCurso = null;

    function marcarActivo(periodo) {
        links.forEach(function (link) {
            var activo = link.dataset.periodo === periodo;
            link.classList.toggle("activo", activo);
            if (activo) {
                link.setAttribute("aria-current", "true");
            } else {
                link.removeAttribute("aria-current");
            }
        });
    }

    function cargar(periodo, guardarEnHistorial) {
        if (pedidoEnCurso) {
            pedidoEnCurso.abort();
        }
        pedidoEnCurso = new AbortController();
        marcarActivo(periodo);
        document.body.classList.add("admin-cargando");

        fetch("/admin/datos?periodo=" + encodeURIComponent(periodo), { signal: pedidoEnCurso.signal })
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
                aciertos.innerHTML = json.aciertos;
                // El gráfico de partidas cambia de unidad según el período (horas, días o meses).
                document.getElementById("titulo-tiempo").textContent = json.graficos.tiempo.titulo;
                graficos.tiempo.options.scales.x.title.text = json.graficos.tiempo.ejeX;
                Object.keys(graficos).forEach(function (nombre) {
                    actualizar(graficos[nombre], json.graficos[nombre]);
                });
                document.getElementById("admin-pdf").href = "/admin/pdf?periodo=" + json.periodo;
                document.body.classList.remove("admin-cargando");
                pedidoEnCurso = null;

                if (guardarEnHistorial) {
                    history.pushState({ periodo: json.periodo }, "", "/admin?periodo=" + json.periodo);
                }
            })
            .catch(function (error) {
                if (error.name === "AbortError") {
                    return;
                }
                window.location.href = "/admin?periodo=" + encodeURIComponent(periodo);
            });
    }

    links.forEach(function (link) {
        link.addEventListener("click", function (evento) {
            evento.preventDefault();
            cargar(link.dataset.periodo, true);
        });
    });

    // Botones atrás/adelante del navegador: vuelve al período de esa entrada del historial.
    window.addEventListener("popstate", function () {
        var periodo = new URLSearchParams(location.search).get("periodo") || "semana";
        cargar(periodo, false);
    });
})();
