// "Reportar pregunta" con AJAX: el modal manda el motivo con fetch (POST /pregunta/reportar)
// y el servidor contesta JSON {ok, mensaje, restantes}. La página no se recarga.
(function () {
    var dialogo = document.getElementById("reporte-dialogo");
    var abrir = document.querySelector("[data-abrir-reporte]");
    if (!dialogo || !abrir) {
        return;
    }

    var formulario = dialogo.querySelector("[data-reporte-form]");
    var mensaje = dialogo.querySelector("[data-reporte-mensaje]");
    var enviar = formulario.querySelector("button[type=submit]");

    abrir.addEventListener("click", function () {
        mensaje.textContent = "";
        dialogo.showModal();
    });

    dialogo.querySelector("[data-cerrar-reporte]").addEventListener("click", function () {
        dialogo.close();
    });

    formulario.addEventListener("submit", function (evento) {
        evento.preventDefault(); // sin esto el navegador cambiaría de página
        enviar.disabled = true;
        mensaje.className = "reporte-mensaje";
        mensaje.textContent = "Enviando...";

        fetch(formulario.action, {
            method: "POST",
            body: new FormData(formulario), // pregunta_id y motivo, como un formulario común
            headers: { "Accept": "application/json" }
        })
            .then(function (respuesta) {
                return respuesta.json();
            })
            .then(function (datos) {
                if (datos.ok) {
                    // Listo: se cierra el modal y el botón queda como "Reportada".
                    dialogo.close();
                    var caja = abrir.parentNode;
                    caja.innerHTML = "";
                    var aviso = document.createElement("p");
                    aviso.className = "reportar-estado reportar-ok";
                    aviso.textContent = datos.mensaje;
                    caja.appendChild(aviso);
                } else {
                    mensaje.className = "reporte-mensaje reporte-error";
                    mensaje.textContent = datos.mensaje;
                    enviar.disabled = false;
                }
            })
            .catch(function () {
                mensaje.className = "reporte-mensaje reporte-error";
                mensaje.textContent = "No se pudo enviar. Probá de nuevo.";
                enviar.disabled = false;
            });
    });
})();
