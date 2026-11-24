// Pantallas de la partida: mezcla de cartas, carta que se da vuelta y reloj de la pregunta.
// Todo es visual: la categoría, la pregunta y si llegaste a tiempo los decide el servidor.
// Si GSAP no carga o el sistema pide menos animaciones, todo sigue andando sin animar.
(function () {
    var sinAnimaciones = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var hayGsap = typeof window.gsap !== "undefined" && !sinAnimaciones;

    // Sonidos (public/js/sonidos.js). Si no cargó, la partida sigue igual, en silencio.
    if (typeof window.Sonido !== "undefined") {
        window.Sonido.precargar(["carta", "tic", "ultimos", "mezclar", "hover", "respuesta"]);
    }

    function sonar(nombre, volumen) {
        if (typeof window.Sonido !== "undefined") {
            window.Sonido.reproducir(nombre, volumen ? { volumen: volumen } : undefined);
        }
    }

    // ---------- Cartas: se juntan, se mezclan y se reparten; después se puede elegir ----------

    var mazo = document.querySelector("[data-cartas]");
    if (mazo) {
        var cartas = Array.prototype.slice.call(mazo.querySelectorAll(".carta"));
        var elegida = false;

        function habilitar(si) {
            cartas.forEach(function (carta) { carta.disabled = !si; });
        }

        function mezclar() {
            habilitar(false);

            var caja = mazo.getBoundingClientRect();
            var centroX = caja.left + caja.width / 2;
            var centroY = caja.top + caja.height / 2;

            // Cuánto tiene que moverse cada carta para quedar en el centro del mazo.
            function haciaElCentro(carta) {
                var r = carta.getBoundingClientRect();
                return { x: centroX - (r.left + r.width / 2), y: centroY - (r.top + r.height / 2) };
            }

            var tl = gsap.timeline({ onComplete: function () {
                // GSAP deja escrito en cada carta "scale: none" (y su transform): se borra al
                // terminar la mezcla para que funcione el agrandado del :hover del CSS.
                gsap.set(cartas, { clearProps: "transform,translate,rotate,scale" });
                habilitar(true);
            } });

            // 1. Se juntan todas en el centro, apiladas y un poco giradas.
            tl.to(cartas, {
                x: function (i, carta) { return haciaElCentro(carta).x; },
                y: function (i, carta) { return haciaElCentro(carta).y; },
                rotation: function () { return gsap.utils.random(-12, 12); },
                duration: 0.5,
                ease: "power2.in",
                stagger: 0.05
            });

            // 2. Se mezclan: cada carta sale para un costado y vuelve al centro, tres veces.
            for (var vuelta = 0; vuelta < 3; vuelta++) {
                tl.to(cartas, {
                    x: function (i, carta) { return haciaElCentro(carta).x + gsap.utils.random(-60, 60); },
                    rotation: function () { return gsap.utils.random(-20, 20); },
                    duration: 0.18,
                    ease: "power1.out",
                    stagger: { each: 0.03, from: "random" }
                }).to(cartas, {
                    x: function (i, carta) { return haciaElCentro(carta).x; },
                    duration: 0.18,
                    ease: "power1.in",
                    stagger: { each: 0.03, from: "random" }
                });
            }

            // 3. Se reparten de vuelta a su lugar, con el sonido de repartir cartas.
            tl.call(function () { sonar("mezclar"); });
            tl.to(cartas, { x: 0, y: 0, rotation: 0, duration: 0.6, ease: "back.out(1.4)", stagger: 0.06 });
        }

        // Hover: un "pop" muy suave al pasar el mouse por una carta que ya se puede elegir.
        // Solo con mouse: en el celular no hay hover y sonaría junto con el toque.
        cartas.forEach(function (carta) {
            carta.addEventListener("pointerenter", function (e) {
                if (e.pointerType === "mouse" && !carta.disabled && !elegida) {
                    sonar("hover", 0.3);
                }
            });
        });

        // Al tocar una: se levanta y las demás se apagan; se da vuelta en la pantalla de la pregunta.
        cartas.forEach(function (carta) {
            carta.addEventListener("click", function (e) {
                if (!elegida) {
                    sonar("carta");
                }
                if (!hayGsap) {
                    return;
                }
                e.preventDefault();
                if (elegida) {
                    return;
                }
                elegida = true;
                habilitar(false);

                gsap.timeline({ onComplete: function () { mazo.submit(); } })
                    .to(cartas.filter(function (c) { return c !== carta; }), { opacity: 0.25, duration: 0.3 })
                    .to(carta, { y: -16, scale: 1.15, duration: 0.3, ease: "power2.out" }, "<");
            });
        });

        if (hayGsap) {
            mezclar();
        }
    }

    // ---------- La carta elegida se da vuelta y muestra la categoría ----------
    // Recién elegida, la carta aparece grande en el medio, se da vuelta mostrando la categoría
    // y después sube a su lugar; recién ahí aparecen la pregunta y el reloj. El servidor arranca
    // el reloj de la pregunta después de esos segundos (data-espera), así no se descuentan.

    var revelada = document.querySelector("[data-revelar]");
    var cuerpo = document.querySelector("[data-cuerpo]");
    var espera = revelada ? parseInt(revelada.dataset.espera, 10) || 0 : 0;

    if (revelada && cuerpo && espera > 0 && hayGsap) {
        revelada.classList.add("boca-abajo");
        gsap.set(cuerpo, { autoAlpha: 0 });
        gsap.set(revelada, { scale: 1.1, y: 120 });

        var subir = 0.5;
        var mostrar = 0.3;
        var girar = 0.5;
        var pausa = Math.max(0.3, espera - girar - subir - mostrar);

        gsap.timeline()
            .to(revelada, { rotationY: 90, duration: girar / 2, ease: "power1.in" })
            .call(function () { revelada.classList.remove("boca-abajo"); })
            .to(revelada, { rotationY: 0, duration: girar / 2, ease: "power1.out" })
            .to(revelada, { scale: 1, y: 0, duration: subir, ease: "power2.inOut" }, "+=" + pausa)
            .to(cuerpo, { autoAlpha: 1, duration: mostrar });
    }

    // ---------- Reloj de la pregunta ----------

    var reloj = document.querySelector("[data-reloj]");
    var opciones = document.querySelector("[data-opciones]");
    if (reloj && opciones) {
        var total = parseInt(reloj.dataset.total, 10);
        var restante = parseInt(reloj.dataset.restante, 10);
        // El reloj empieza a bajar cuando termina de mostrarse la carta.
        var vence = Date.now() + (espera + restante) * 1000;
        var numero = reloj.querySelector(".reloj-numero");
        var relleno = reloj.querySelector(".reloj-relleno");
        var enviada = false;
        var ultimoSegundo = null;

        // Sonido al elegir una respuesta. Suena al apoyar el dedo o el mouse (pointerdown), un
        // instante antes del envío, así la página nueva no lo corta. Con teclado, en el click.
        function sonarRespuesta(e) {
            if (!enviada && e.target.closest(".opcion-respuesta")) {
                sonar("respuesta");
            }
        }
        opciones.addEventListener("pointerdown", sonarRespuesta);
        opciones.addEventListener("click", function (e) {
            if (e.detail === 0) {
                sonarRespuesta(e);
            }
        });

        // Al elegir una opción: se bloquean las demás (después del envío, así viaja el valor elegido).
        opciones.addEventListener("submit", function () {
            enviada = true;
            setTimeout(function () {
                opciones.querySelectorAll("button").forEach(function (b) { b.disabled = true; });
            }, 0);
        });

        function actualizar() {
            var segundos = Math.min(total, Math.max(0, Math.ceil((vence - Date.now()) / 1000)));
            numero.textContent = segundos;
            relleno.style.width = (segundos / total * 100) + "%";
            reloj.classList.toggle("reloj-urgente", segundos <= 10);

            // Tic en cada segundo de los últimos 15, y doble tic en los últimos 5.
            if (segundos !== ultimoSegundo && !enviada) {
                if (ultimoSegundo !== null && segundos > 0 && segundos <= 15) {
                    sonar(segundos <= 5 ? "ultimos" : "tic");
                }
                ultimoSegundo = segundos;
            }

            if (segundos === 0) {
                // Se acabó el tiempo: se manda sin respuesta y el servidor la cuenta como error.
                if (!enviada) {
                    enviada = true;
                    opciones.submit();
                }
                return;
            }
            setTimeout(actualizar, 250);
        }

        actualizar();
    }
})();
