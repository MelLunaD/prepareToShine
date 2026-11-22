// Menú hamburguesa del header. Abrir y cerrar lo hace el <details> solo;
// esto solo lo cierra al tocar fuera del menú o al apretar Escape.
(function () {
    var menu = document.querySelector(".hud-menu");
    if (!menu) {
        return;
    }

    document.addEventListener("click", function (evento) {
        if (menu.open && !menu.contains(evento.target)) {
            menu.open = false;
        }
    });

    document.addEventListener("keydown", function (evento) {
        if (evento.key === "Escape" && menu.open) {
            menu.open = false;
            menu.querySelector("summary").focus();
        }
    });
})();
