// Panel del administrador, página de una pregunta: los tres gráficos de "bien y mal"
// (por nivel del jugador, por edad y por mes), con Chart.js. Los datos vienen en la página como JSON.
(function () {
    var datosDeLaPagina = document.getElementById("admin-pregunta-datos");
    if (!datosDeLaPagina || typeof Chart === "undefined") {
        return;
    }

    var css = getComputedStyle(document.body);
    function color(variable, otro) {
        return css.getPropertyValue(variable).trim() || otro;
    }
    var texto = color("--texto-suave", "#98a1b3");
    var verde = color("--verde", "#3fb950");
    var rojo = color("--rojo", "#e5484d");

    Chart.defaults.color = texto;
    Chart.defaults.font.family = "Nunito, sans-serif";
    Chart.defaults.font.size = 13;
    Chart.defaults.borderColor = "rgba(255, 255, 255, 0.08)";
    Chart.defaults.maintainAspectRatio = false;

    var datos = JSON.parse(datosDeLaPagina.textContent);

    // Barras apiladas: abajo las bien respondidas (verde) y arriba las mal (rojo).
    // ejeX: qué es cada barra, para que el gráfico se entienda solo.
    // todasLasEtiquetas: true en el de niveles (N0 a N10 entran derechas con letra más chica).
    function bienYMal(id, serie, ejeX, todasLasEtiquetas) {
        return new Chart(document.getElementById(id), {
            type: "bar",
            data: {
                labels: serie.etiquetas,
                datasets: [
                    { label: "Bien", data: serie.bien, backgroundColor: verde, maxBarThickness: 40 },
                    { label: "Mal", data: serie.mal, backgroundColor: rojo, maxBarThickness: 40 }
                ]
            },
            options: {
                plugins: {
                    legend: { display: true, position: "bottom", labels: { boxWidth: 12, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            // "Bien: 7 de 10 (70%)"
                            label: function (item) {
                                var total = serie.bien[item.dataIndex] + serie.mal[item.dataIndex];
                                var porcentaje = total > 0 ? Math.round(item.raw * 100 / total) : 0;
                                return item.dataset.label + ": " + item.raw + " de " + total + " (" + porcentaje + "%)";
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: todasLasEtiquetas
                            ? { autoSkip: false, maxRotation: 0, font: { size: window.innerWidth < 600 ? 10 : 13 } }
                            : {},
                        title: { display: true, text: ejeX, color: texto }
                    },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: "Respuestas", color: texto } }
                }
            }
        });
    }

    bienYMal("grafico-nivel", datos.nivel, "Nivel del jugador al empezar la partida", true);
    bienYMal("grafico-edad-pregunta", datos.edad, "Edad (años)");
    bienYMal("grafico-meses", datos.tiempo, "Mes");
})();
