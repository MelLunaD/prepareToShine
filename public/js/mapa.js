// Mapa de ubicación con Leaflet (public/js/vendor/leaflet.js) y los mapas de OpenStreetMap.
// - En el registro (data-modo="elegir"): el jugador toca el mapa o busca su ciudad, y con
//   Nominatim (el buscador de OpenStreetMap) se completan ciudad, provincia y país en los
//   campos ocultos del formulario. El servidor valida esos datos al crear la cuenta.
// - En el perfil (data-modo="ver"): muestra el pin de la ubicación guardada, sin poder moverlo.
// Nominatim es gratis y sin API key, pero pide como máximo 1 consulta por segundo:
// por eso solo se consulta al tocar el mapa o al apretar "Buscar", nunca mientras se escribe.
(function () {
    var NOMINATIM = "https://nominatim.openstreetmap.org";
    var CENTRO_INICIAL = [-38.4, -63.6]; // Argentina
    var ZOOM_INICIAL = 4;
    var ZOOM_ELEGIDO = 11;

    var contenedor = document.getElementById("mapa");
    if (!contenedor || typeof window.L === "undefined") {
        return;
    }

    var lat = parseFloat(contenedor.dataset.lat);
    var lng = parseFloat(contenedor.dataset.lng);
    var hayPunto = !isNaN(lat) && !isNaN(lng);

    var mapa = L.map(contenedor, { scrollWheelZoom: false })
        .setView(hayPunto ? [lat, lng] : CENTRO_INICIAL, hayPunto ? ZOOM_ELEGIDO : ZOOM_INICIAL);

    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(mapa);

    var pin = hayPunto ? L.marker([lat, lng]).addTo(mapa) : null;

    if (contenedor.dataset.modo !== "elegir") {
        return;
    }

    var campos = {
        latitud: document.getElementById("latitud"),
        longitud: document.getElementById("longitud"),
        ciudad: document.getElementById("ciudad"),
        provincia: document.getElementById("provincia"),
        paisCodigo: document.getElementById("pais_codigo"),
        paisNombre: document.getElementById("pais_nombre")
    };
    var textoUbicacion = document.getElementById("mapa-ubicacion");
    var busqueda = document.getElementById("mapa-busqueda");
    var botonBuscar = document.getElementById("mapa-buscar");

    function mostrar(texto, esError) {
        textoUbicacion.textContent = texto;
        textoUbicacion.classList.toggle("mapa-ubicacion-error", !!esError);
    }

    function limpiarCampos() {
        Object.keys(campos).forEach(function (clave) {
            campos[clave].value = "";
        });
    }

    function ponerPin(latlng) {
        if (pin) {
            pin.setLatLng(latlng);
        } else {
            pin = L.marker(latlng).addTo(mapa);
        }
    }

    // Nominatim no siempre devuelve "city": en pueblos viene como town, village, etc.
    function ciudadDe(direccion) {
        return direccion.city || direccion.town || direccion.village || direccion.municipality
            || direccion.county || direccion.state_district || "";
    }

    function elegir(latlng) {
        ponerPin(latlng);
        limpiarCampos();
        mostrar("Buscando dónde es...");

        var url = NOMINATIM + "/reverse?format=jsonv2&zoom=10&addressdetails=1&accept-language=es"
            + "&lat=" + latlng.lat + "&lon=" + latlng.lng;

        fetch(url)
            .then(function (respuesta) { return respuesta.json(); })
            .then(function (lugar) {
                var direccion = lugar.address || {};
                if (!direccion.country_code) {
                    mostrar("Ahí no encontramos ningún país. Elegí un punto en tierra firme.", true);
                    return;
                }

                campos.latitud.value = latlng.lat.toFixed(6);
                campos.longitud.value = latlng.lng.toFixed(6);
                campos.ciudad.value = ciudadDe(direccion);
                campos.provincia.value = direccion.state || "";
                campos.paisCodigo.value = direccion.country_code.toUpperCase();
                campos.paisNombre.value = direccion.country || "";

                mostrar([campos.ciudad.value, campos.provincia.value, campos.paisNombre.value]
                    .filter(Boolean).join(", "));
            })
            .catch(function () {
                mostrar("No pudimos buscar esa ubicación. Probá de nuevo en un rato.", true);
            });
    }

    function buscar() {
        var texto = busqueda.value.trim();
        if (texto === "") {
            return;
        }
        mostrar("Buscando \"" + texto + "\"...");

        fetch(NOMINATIM + "/search?format=jsonv2&limit=1&accept-language=es&q=" + encodeURIComponent(texto))
            .then(function (respuesta) { return respuesta.json(); })
            .then(function (resultados) {
                if (resultados.length === 0) {
                    mostrar("No encontramos \"" + texto + "\". Probá con otro nombre o tocá el mapa.", true);
                    return;
                }
                var latlng = L.latLng(parseFloat(resultados[0].lat), parseFloat(resultados[0].lon));
                mapa.setView(latlng, ZOOM_ELEGIDO);
                elegir(latlng);
            })
            .catch(function () {
                mostrar("No pudimos buscar. Probá de nuevo en un rato.", true);
            });
    }

    mapa.on("click", function (evento) {
        elegir(evento.latlng);
    });

    botonBuscar.addEventListener("click", buscar);

    // Enter en el buscador busca en el mapa en vez de mandar el formulario de registro.
    busqueda.addEventListener("keydown", function (evento) {
        if (evento.key === "Enter") {
            evento.preventDefault();
            buscar();
        }
    });
})();
