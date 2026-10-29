// Secreto de la portada: clickear 5 veces el "Shine" del título
// hace que tiemble, se caiga, "se apague la luz" y el juego pase
// a "Prepare to Die". Las animaciones están hechas con GSAP
// (public/js/vendor/gsap.min.js). Guía: notas/gsap-guia.md
(function () {
    var CLICKS_PARA_CAER = 5;

    var palabra = document.querySelector(".titulo-juego .palabra-fase");
    if (!palabra || !document.body.classList.contains("tema-shine")) {
        return;
    }

    var clicks = 0;
    var sinAnimaciones = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var hayGsap = typeof window.gsap !== "undefined";

    palabra.classList.add("palabra-secreta");
    palabra.setAttribute("role", "button");
    palabra.setAttribute("tabindex", "0");

    // El "telón": una capa negra que tapa toda la pantalla mientras
    // cambian los estilos, así el salto de Shine a Souls no se ve.
    var telon = document.createElement("div");
    telon.className = "telon";
    document.body.appendChild(telon);

    // Cambia todo lo que depende del tema. Pasa con la pantalla a oscuras.
    function cambiarATemaSouls() {
        document.body.classList.remove("tema-shine");
        document.body.classList.add("tema-souls");
        document.title = "Prepare to Die";

        var favicon = document.querySelector('link[rel="icon"]');
        if (favicon) {
            favicon.href = "/public/img/favicon-souls.ico";
        }

        // Cada título puede traer su versión Souls en data-die ("Mi Perfil" → "Mi Alma");
        // la palabra de color que no la trae pasa a "Die" ("Prepare to Shine" → "Prepare to Die").
        document.querySelectorAll(".palabra-fase, [data-die]").forEach(function (el) {
            el.textContent = el.dataset.die || "Die";
        });

        palabra.classList.remove("palabra-secreta");
        palabra.removeAttribute("role");
        palabra.removeAttribute("tabindex");
    }

    // Clicks 1 a 4: la palabra tiembla, cada vez un poco más fuerte.
    function temblar() {
        var fuerza = clicks * 1.5;

        // gsap.fromTo(elemento, desde, hasta): anima entre dos estados.
        // "keyframes" recorre varios valores seguidos dentro de la misma animación.
        gsap.fromTo(palabra,
            { x: 0, rotation: 0 },
            {
                keyframes: {
                    x: [-fuerza, fuerza, -fuerza, fuerza, 0],
                    rotation: [-fuerza, fuerza, -fuerza / 2, fuerza / 2, 0]
                },
                duration: 0.4,
                ease: "none"
            }
        );
    }

    // Click 5: una línea de tiempo (timeline) encadena los pasos.
    // Cada .to() arranca cuando termina el anterior, salvo que el
    // último parámetro diga otra cosa ("<", "-=0.4", "+=0.3").
    function caerYApagarLaLuz() {
        var tl = gsap.timeline();

        tl
            // 1. Pequeño salto hacia arriba, como sorprendida.
            .to(palabra, { y: -20, rotation: -8, duration: 0.25, ease: "power2.out" })

            // 2. Se cae fuera de la pantalla girando y desvaneciéndose.
            .to(palabra, { y: "110vh", rotation: 40, opacity: 0, duration: 1.1, ease: "power2.in" })

            // 3. Al mismo tiempo ("<" = junto con el paso anterior), se van las nubes y los bloques.
            .to(".nube, .bloques", { opacity: 0, duration: 0.9 }, "<")

            // 4. Se apaga la luz: el telón pasa a negro, empezando antes de que termine la caída.
            .to(telon, { opacity: 1, duration: 1, ease: "power1.inOut" }, "-=0.5")

            // 5. Con la pantalla a oscuras se cambia el tema.
            .call(cambiarATemaSouls)

            // 6. Deja todo listo para reaparecer: la palabra en su lugar, invisible, grande y
            //    borrosa, y los pasos de la tarjeta invisibles y un poco más abajo.
            .set(palabra, { y: 0, rotation: 0, opacity: 0, scale: 1.6, filter: "blur(10px)" })
            .set(".tarjeta h2, .pasos li", { opacity: 0, y: 12 })

            // 7. La luz vuelve despacio, después de una pausa breve ("+=0.4").
            .to(telon, { opacity: 0, duration: 1.8, ease: "power2.out" }, "+=0.4")

            // 8. "Die" aparece mientras la luz vuelve ("-=1.0" = arranca 1 s antes de que termine el paso 7).
            .to(palabra, { opacity: 1, scale: 1, filter: "blur(0px)", duration: 1.6, ease: "power3.out" }, "-=1.0")

            // 9. Los pasos de "Lo que te espera" aparecen de a uno: "stagger" separa
            //    cada elemento 0.35 s.
            .to(".tarjeta h2, .pasos li", { opacity: 1, y: 0, duration: 0.8, stagger: 0.35, ease: "power2.out" }, "-=0.8");
    }

    function alClickear() {
        if (clicks >= CLICKS_PARA_CAER) {
            return;
        }
        clicks++;

        // Sin GSAP (no cargó) o con animaciones desactivadas en el sistema: cambio directo.
        if (!hayGsap || sinAnimaciones) {
            if (clicks === CLICKS_PARA_CAER) {
                cambiarATemaSouls();
            }
            return;
        }

        if (clicks < CLICKS_PARA_CAER) {
            temblar();
        } else {
            caerYApagarLaLuz();
        }
    }

    palabra.addEventListener("click", alClickear);
    palabra.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            alClickear();
        }
    });
})();
