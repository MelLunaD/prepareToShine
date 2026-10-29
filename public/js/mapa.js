// Mapa de ubicación con Leaflet (public/js/vendor/leaflet.js) y los mapas de OpenStreetMap (estilo de CARTO).
// - En el registro (data-modo="elegir"): el jugador toca el mapa o busca su ciudad, y con
//   Nominatim (el buscador de OpenStreetMap) se completan ciudad, provincia y país en los
//   campos ocultos del formulario. El servidor valida esos datos al crear la cuenta.
// - En el perfil propio (data-modo="ver"): muestra el pin de la ubicación guardada, sin poder moverlo.
// - En el perfil de otro jugador (data-modo="zona"): no muestra el punto exacto (ni siquiera llega
//   a la página), solo el contorno de su localidad, que se le pide a Nominatim. Si la localidad no
//   tiene contorno, muestra un círculo aproximado alrededor de su centro.
// Nominatim es gratis y sin API key, pero pide como máximo 1 consulta por segundo:
// por eso solo se consulta al tocar el mapa o al apretar "Buscar", nunca mientras se escribe.
(function () {
    var NOMINATIM = "https://nominatim.openstreetmap.org";
    var CENTRO_INICIAL = [-38.4, -63.6]; // Argentina
    var ZOOM_INICIAL = 4;
    var ZOOM_ELEGIDO = 11;
    var RADIO_APROXIMADO = 5000; // metros, para las localidades sin contorno

    var contenedor = document.getElementById("mapa");
    if (!contenedor || typeof window.L === "undefined") {
        return;
    }

    var lat = parseFloat(contenedor.dataset.lat);
    var lng = parseFloat(contenedor.dataset.lng);
    var hayPunto = !isNaN(lat) && !isNaN(lng);

    var mapa = L.map(contenedor)
        .setView(hayPunto ? [lat, lng] : CENTRO_INICIAL, hayPunto ? ZOOM_ELEGIDO : ZOOM_INICIAL);

    // Estilo del mapa según el tema de la página (lo elige el controller): CARTO Voyager para
    // Shine y CARTO Dark Matter para Souls. Son gratis para proyectos no comerciales, sin API key,
    // y piden citar a CARTO además de OpenStreetMap.
    var estiloMapa = document.body.classList.contains("tema-souls") ? "dark_all" : "rastertiles/voyager";
    L.tileLayer("https://{s}.basemaps.cartocdn.com/" + estiloMapa + "/{z}/{x}/{y}{r}.png", {
        subdomains: "abcd",
        maxZoom: 20,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            + ' &copy; <a href="https://carto.com/attributions">CARTO</a>'
    }).addTo(mapa);

    if (contenedor.dataset.modo === "zona") {
        mostrarZona();
        return;
    }

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
        paisNombre: document.getElementById("pais_nombre"),
        osmLugar: document.getElementById("osm_lugar")
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
                // Identificador de la localidad en OpenStreetMap (ej: R1234 para una "relation"),
                // para después dibujar su contorno en el perfil sin mostrar el punto exacto.
                campos.osmLugar.value = lugar.osm_type && lugar.osm_id
                    ? lugar.osm_type.charAt(0).toUpperCase() + lugar.osm_id : "";

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

    // Perfil de otro jugador: dibuja el contorno de su localidad. Primero la busca por su
    // identificador de OpenStreetMap y, si no lo tiene (usuarios viejos), por "ciudad, provincia, país".
    function mostrarZona() {
        var lugarOsm = contenedor.dataset.lugar;
        var url = lugarOsm
            ? NOMINATIM + "/lookup?format=jsonv2&polygon_geojson=1&polygon_threshold=0.001&osm_ids=" + encodeURIComponent(lugarOsm)
            : NOMINATIM + "/search?format=jsonv2&limit=1&polygon_geojson=1&polygon_threshold=0.001&q="
                + encodeURIComponent(contenedor.dataset.busqueda || "");

        var estilo = { color: "#e52521", weight: 3, fillOpacity: 0.15 };

        fetch(url)
            .then(function (respuesta) { return respuesta.json(); })
            .then(function (resultados) {
                var lugar = resultados[0];
                if (!lugar) {
                    return;
                }
                var forma = lugar.geojson && lugar.geojson.type;
                var capa = forma === "Polygon" || forma === "MultiPolygon"
                    ? L.geoJSON(lugar.geojson, { style: estilo })
                    : L.circle([parseFloat(lugar.lat), parseFloat(lugar.lon)], L.extend({ radius: RADIO_APROXIMADO }, estilo));
                capa.addTo(mapa);
                mapa.fitBounds(capa.getBounds());
            })
            .catch(function () {
                // Sin respuesta de Nominatim el mapa queda en la vista general.
            });
    }
})();
