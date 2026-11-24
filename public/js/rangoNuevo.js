// Resultado de la partida: si subió de rango, abre el cartel (dialog) apenas carga la página.
// El ícono aparece con un salto (GSAP) y el nombre se ilumina después.
(function () {
    var dialogo = document.getElementById("rango-dialogo");
    if (!dialogo || typeof dialogo.showModal !== "function") {
        return;
    }

    // Un momento después de cargar, para que primero se vea el resultado.
    setTimeout(function () {
        dialogo.showModal();

        var sinMovimiento = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        if (typeof gsap === "undefined" || sinMovimiento) {
            return;
        }
        gsap.timeline()
            .from(dialogo, { scale: 0.7, opacity: 0, duration: 0.35, ease: "back.out(1.7)" })
            .from(dialogo.querySelector(".rango-dialogo-icono"), { y: 30, scale: 0.2, rotation: -20, duration: 0.6, ease: "back.out(2.5)" }, "-=0.1")
            .from(dialogo.querySelector(".rango-dialogo-nombre"), { opacity: 0, y: 10, duration: 0.4 }, "-=0.2");
    }, 600);

    // Click afuera del cartel también lo cierra.
    dialogo.addEventListener("click", function (e) {
        if (e.target === dialogo) {
            dialogo.close();
        }
    });
})();
