<?php

namespace app\model;

// Cementerio de Die: cuántas veces murió cada jugador y qué dice su lápida.
// Una muerte es una partida de Die que terminó por un error o porque se acabó el tiempo
// (tiene una respuesta con acerto = 0). Retirarse en una hoguera no cuenta.
class CementerioModel
{
    const CANTIDAD_EN_CEMENTERIO = 10;

    // Tramos de muertes: [desde, hasta]. El último no tiene techo.
    const TRAMOS = [
        [1, 4],
        [5, 14],
        [15, 29],
        [30, 50],
        [51, 100],
        [101, PHP_INT_MAX],
    ];

    // Frases de las lápidas, 50 por tramo, en el mismo orden que TRAMOS.
    const FRASES = [
        // 1 a 4: recién llegado
        [
            "Recién se está calentando.",
            "Murió aprendiendo.",
            "Vino a mirar y se quedó a morir.",
            "Pensó que era un juego de preguntas tranquilo.",
            "Todavía cree que la próxima sale.",
            "Le dijeron \"prepare to die\" y se lo tomó literal.",
            "Murió con la sonrisa puesta.",
            "Apenas abrió la puerta y ya estaba acá.",
            "Era su primer día y se le notó.",
            "Confió en su instinto. Error.",
            "Ni llegó a encender la hoguera.",
            "Dijo \"esta la sé\" y no la sabía.",
            "Visitante ocasional del más allá.",
            "Murió, pero con estilo de turista.",
            "Le faltó leer el manual. Y la pregunta.",
            "Se tropezó en el tutorial.",
            "Su primera muerte nunca se olvida.",
            "Todavía no sabe dónde quedó su alma.",
            "Vino con fe y se fue con una lápida.",
            "Respondió rápido. Demasiado rápido.",
            "Apostó a la C. Era la B.",
            "Morir un poco no le hace mal a nadie.",
            "Está acá de paso, dice.",
            "Pensó que el reloj era decorativo.",
            "Dejó las almas tiradas y ni se dio cuenta.",
            "Se le apagó la luz antes de tiempo.",
            "Prometió volver. Todos prometen volver.",
            "Lo mató la ansiedad, no la pregunta.",
            "Murió por curioso.",
            "Recién empieza a conocer el piso.",
            "Hizo click sin pensar. Pagó caro.",
            "Le quedó grande el modo Die.",
            "Sus almas lo extrañan.",
            "Aprendiz de difunto.",
            "Dijo \"fácil\" en voz alta.",
            "El cementerio le guardó un lugar, por las dudas.",
            "Sobrevivió al Shine. Al Die, no tanto.",
            "Se asustó con el título en rojo.",
            "Leyó \"Jefes\" y se le fue el alma.",
            "Primera visita, sin reservas.",
            "Lo vio venir y lo mismo murió.",
            "Murió en paz. Bueno, más o menos.",
            "Tenía un buen presentimiento. Falló.",
            "Le pifió por una letra.",
            "Las hogueras todavía no lo conocen.",
            "Su lápida todavía huele a nuevo.",
            "Nadie lo vio caer, pero cayó.",
            "Pensó que retirarse era de cobardes.",
            "Un tropezón no es caída. Cuatro, un poco sí.",
            "Recién está abriendo la cuenta.",
        ],
        // 5 a 14: ya conoce el camino
        [
            "Ya conoce la hoguera de memoria.",
            "Lo intentó con el escudo, no alcanzó.",
            "Ya tiene cliente frecuente en el cementerio.",
            "Murió tantas veces que ya saluda al sepulturero.",
            "Su alma hace el camino sola.",
            "Le tiene cariño a esta lápida.",
            "Juró que esta vez sí. No fue esta vez.",
            "Ya no se sorprende al morir.",
            "Conoce cada baldosa del piso. De cerca.",
            "Tiene la tumba con su nombre grabado a mano.",
            "Le dejó una propina al de las almas.",
            "Dice que muere para estudiar al enemigo.",
            "Morir ya es parte de su rutina.",
            "Siempre se olvida de la misma pregunta.",
            "Las almas que dejó podrían llenar una mochila.",
            "Ya tiene un lado favorito de la lápida.",
            "Su estrategia: morir hasta entender.",
            "Lo conocen en las dos hogueras.",
            "Viene, muere, vuelve. Así es la vida.",
            "Le pusieron una flor, por costumbre.",
            "Es el que más reza antes de cada pregunta.",
            "Ya distingue el sonido de su propia muerte.",
            "Murió de nuevo, pero más prolijo.",
            "Le dicen \"el que vuelve\".",
            "Domina el arte de perder las almas.",
            "Siempre corre hacia sus almas. Siempre llega tarde.",
            "Ya sabe que el reloj no perdona.",
            "Aprendió algo cada vez. Nada útil, pero algo.",
            "Le reservaron un banco en el cementerio.",
            "Su segunda casa tiene cruces.",
            "Murió tanto que ya no pide perdón.",
            "Prometió estudiar el lore. Mintió.",
            "Le tiene miedo a la pregunta 10.",
            "Cada muerte lo hace más fuerte. En teoría.",
            "Tiene más lápidas que trofeos.",
            "Volvió por sus almas y dejó más.",
            "Ya le cambiaron la lápida por una más grande.",
            "Juega con los ojos cerrados, aparentemente.",
            "Su mantra: una más y me voy.",
            "Le dijeron que era fácil. Le mintieron.",
            "Siempre elige la carta equivocada.",
            "La muerte ya lo tiene agendado.",
            "No es mala suerte, es constancia.",
            "Va a volver más fuerte. Ya dijo eso antes.",
            "Lo frenó la misma pregunta tres veces.",
            "Murió en la puerta de la hoguera.",
            "Se lleva bien con los gusanos.",
            "Ya deja la llave del cementerio debajo de la alfombra.",
            "Cree que las almas se recuperan solas.",
            "Si morir diera puntos, estaría primero.",
        ],
        // 15 a 29: habitué
        [
            "Pidió ayuda en los mensajes del suelo.",
            "Su sombra ya juega sola.",
            "Pagó expensas en el cementerio.",
            "Tiene la lápida con vista al jardín.",
            "Ya es parte del paisaje.",
            "Escribió \"prueba con la B\" en el piso. Era la C.",
            "Le hicieron socio vitalicio del cementerio.",
            "Lo conocen hasta los fantasmas.",
            "Murió tantas veces que perdió la cuenta. Nosotros no.",
            "Su lápida tiene más visitas que su perfil.",
            "Las hogueras ya le apagan la luz al verlo.",
            "Vive acá, muere allá, vuelve acá.",
            "Tiene una colección de almas perdidas.",
            "Le cobran estacionamiento en el cementerio.",
            "La muerte ya le dice \"che\".",
            "Rompió el récord de su cuadra.",
            "Morir es su forma de decir hola.",
            "Ya se armó un huerto al lado de la tumba.",
            "Le dejaron un cartel: \"Volvemos en 5\".",
            "Trajo una reposera para esperar la próxima.",
            "Su alma pidió vacaciones.",
            "Ni el temporizador le tiene paciencia.",
            "Le dieron la llave del cementerio.",
            "Hace turismo de lápidas.",
            "Tiene una lápida de repuesto, por las dudas.",
            "El sepulturero ya le cobra por adelantado.",
            "Murió con la respuesta en la punta de la lengua.",
            "Ya no se queja. Se resignó.",
            "Sus almas armaron un grupo de apoyo.",
            "Los fantasmas le piden autógrafos.",
            "Es un clásico del cementerio.",
            "Sabe el lore de su propia muerte.",
            "Muere con una constancia admirable.",
            "Firmó un contrato con la parca.",
            "Su lápida tiene WiFi.",
            "Ya lo saludan con un \"¿otra vez vos?\".",
            "Su rutina: hoguera, pregunta, lápida.",
            "Dice que muere para hacer contenido.",
            "Nadie murió con tanto entusiasmo.",
            "Lleva un mapa del cementerio en el bolsillo.",
            "Ya le pusieron su nombre a una calle del cementerio.",
            "Murió en todas las categorías. Un alma del Renacimiento.",
            "Cada error, una lápida nueva.",
            "La hoguera lo mira con lástima.",
            "Se aprendió el camino de vuelta de memoria.",
            "No pierde la fe. Pierde todo lo demás.",
            "Sus muertes ya tienen temporadas.",
            "El piso lo conoce por el nombre.",
            "Si hubiera un ranking de caídas, ya ganó.",
            "Juega a perder y le sale perfecto.",
        ],
        // 30 a 50: sin esperanza
        [
            "Es más hueco que persona.",
            "La muerte ya lo saluda por el nombre.",
            "Le queda poca humanidad y mucha lápida.",
            "Las almas que perdió fundaron un pueblo.",
            "Ya no muere: se toma un descanso.",
            "El cementerio le hizo un monumento.",
            "Tiene más muertes que respuestas correctas.",
            "Se le está cayendo la piel de tanto volver.",
            "Su lápida tiene nombre, apellido y código postal.",
            "Le cobraron alquiler de la tumba.",
            "Va a heredar el cementerio.",
            "La parca le pidió que se tome un respiro.",
            "Su alma ya ni se molesta en salir.",
            "Le dicen \"el Hueco\" con cariño.",
            "Murió tanto que el juego lo considera un NPC.",
            "Tiene un mausoleo propio.",
            "Ya ni se fija la respuesta. Muere directo.",
            "El reloj se ríe cuando lo ve.",
            "Sus almas pidieron la emancipación.",
            "Hace rato que el cementerio es su casa.",
            "La hoguera le cobra entrada.",
            "Ya tiene los gusanos entrenados.",
            "Las preguntas lo buscan a él.",
            "Su esperanza murió en la muerte 31.",
            "El sepulturero se jubiló por su culpa.",
            "Conoce cada error del juego en persona.",
            "Muere como respira: sin pensar.",
            "Su lápida ya tiene grafitis.",
            "Le diseñaron una tumba ergonómica.",
            "La muerte le pidió una selfie.",
            "Tiene una cuenta corriente de almas en rojo.",
            "Nadie insistió tanto en perder.",
            "Ya se olvidó cómo se ve la pregunta 15.",
            "Le pusieron un buzón en la tumba.",
            "Murió tantas veces que tiene descuento.",
            "Su lápida está en la guía turística.",
            "Es leyenda. Leyenda de lo que no hay que hacer.",
            "Dejó de contar muertes. Ahora cuenta lápidas.",
            "La hoguera le pidió distancia.",
            "Se le gastó el botón de reintentar.",
            "Tiene una relación tóxica con el modo Die.",
            "Ya no tiene almas, tiene recuerdos.",
            "Le cambiaron la lápida por una de goma, para que rebote.",
            "Hizo del cementerio su hábitat natural.",
            "La parca lo usa de ejemplo en las capacitaciones.",
            "Sigue jugando por pura terquedad.",
            "Su espíritu tiene más millas que un piloto.",
            "Cayó tantas veces que el piso le pide perdón.",
            "Ni los fantasmas quieren estar cerca.",
            "Es el vecino más antiguo del cementerio.",
        ],
        // 51 a 100: leyenda del cementerio
        [
            "Ya es un elemento decorativo del cementerio.",
            "Los nuevos vienen a pedirle consejos. Les miente.",
            "Su tumba tiene su propio código QR.",
            "La muerte lo puso en favoritos.",
            "Se ganó una estatua. De rodillas.",
            "Su nombre está tallado en todas las lápidas.",
            "Le dieron un cargo en el cementerio: guía turístico.",
            "Ya es más fantasma que jugador.",
            "Sus almas perdidas tienen su propio ranking.",
            "Vive en el cementerio y visita el juego.",
            "La parca le pidió que baje un cambio.",
            "Lo usan para asustar a los del modo Shine.",
            "Se aprendió las preguntas y lo mismo muere.",
            "Tiene un abono anual de muerte.",
            "La hoguera ya ni prende cuando llega.",
            "Hizo un posgrado en morir.",
            "Hay rumores de que alguna vez ganó. Nadie lo vio.",
            "Su lápida es patrimonio histórico.",
            "Tiene más funerales que cumpleaños.",
            "El cementerio le hizo una placa de agradecimiento.",
            "Es el mejor cliente de la funeraria.",
            "Dicen que su alma sigue corriendo por ahí.",
            "Ya no juega: hace arqueología de sus muertes.",
            "Los gusanos le hacen reverencia.",
            "Tiene un camino propio hacia su lápida.",
            "Muere con experiencia, que no es lo mismo.",
            "La pregunta 1 le tiene lástima.",
            "Hasta el temporizador le quiere dar una mano.",
            "Le hicieron un documental: \"Morir 100 veces\".",
            "Inventó tres formas nuevas de perder.",
            "Su alma pidió un abogado.",
            "Pagaría por una hoguera más.",
            "El modo Die lo usa de tutorial.",
            "La muerte ya le prepara el café.",
            "Su lápida tiene calefacción.",
            "Es más conocido en el cementerio que en el lobby.",
            "Con sus almas perdidas se podría comprar el juego.",
            "Ya no grita al morir. Suspira.",
            "Tiene un fan club de fantasmas.",
            "Le reservaron una parcela VIP.",
            "Murió tanto que le dieron la ciudadanía del más allá.",
            "Lo ves volver y ya sabés cómo termina.",
            "Le cambiaron el nombre a la pregunta que siempre falla.",
            "Muere en piloto automático.",
            "La parca le pidió que le enseñe.",
            "Sus muertes ya tienen spin-off.",
            "Si le pagaran por morir, sería millonario.",
            "La lápida está gastada de tanto tallarle números.",
            "El cementerio ya tiene un ala con su nombre.",
            "Nadie sabe por qué sigue. Él tampoco.",
        ],
        // Más de 100: el peor
        [
            "El cementerio es suyo. Nosotros alquilamos.",
            "La muerte le tiene miedo a él.",
            "Ya no es un jugador: es una advertencia.",
            "Murió más veces que las preguntas que hay.",
            "Su lápida se ve desde el espacio.",
            "Hasta los huecos le tienen lástima.",
            "Rompió el contador de muertes. Dos veces.",
            "La parca pidió licencia por estrés.",
            "Su alma presentó la renuncia.",
            "Dicen que la hoguera se apagó para siempre por él.",
            "Es el jefe final del cementerio.",
            "Si morir fuera deporte, tendría medalla de oro.",
            "Tiene su propio cementerio adentro del cementerio.",
            "Ya no tiene almas: tiene deudas.",
            "Hasta el modo Shine le queda grande ahora.",
            "Su nombre se usa como insulto en el más allá.",
            "La funeraria le puso su cara en el logo.",
            "Es el motivo por el que existe este cementerio.",
            "Se le terminaron las lápidas. Ahora le ponen piedras.",
            "Los fantasmas nuevos hacen fila para verlo.",
            "Ni el código del juego entiende cómo sigue.",
            "Batió el récord. El de morir.",
            "Ya es más lápida que persona.",
            "Le dieron el título honorífico de Muerto Supremo.",
            "Su historia de muertes ocupa tres servidores.",
            "La pregunta fácil le ganó. Muchas veces.",
            "No hay frase que lo describa. Pero lo intentamos.",
            "Inmortal en su forma de morir.",
            "Cada hoguera tiene una foto suya con un cartel de \"cuidado\".",
            "Si muere una vez más, el cementerio pide ampliación.",
            "El reloj se pone en pausa por respeto.",
            "Sus almas perdidas armaron una ciudad.",
            "Ya no tiene esperanza, tiene costumbre.",
            "Es el ejemplo de lo que no hay que hacer.",
            "Lo nombraron rey de los huecos.",
            "Tiene más muertes que todos los demás juntos. Casi.",
            "La muerte le firmó un autógrafo.",
            "Ni Shine ni Die: él juega a Prepare to Lápida.",
            "Le hicieron una estatua y también se murió.",
            "Su lápida tiene índice y apéndice.",
            "Hay estudiantes que hacen la tesis sobre él.",
            "El juego le pidió que lo deje en paz.",
            "Volvió tantas veces que la muerte se aburrió.",
            "Es el santo patrono de los que pifian.",
            "Murió de todas las formas posibles, y de algunas imposibles.",
            "Si hay un récord Guinness de morir, está acá.",
            "Los gusanos ya le cobran como inquilino.",
            "Más de cien veces. Y sigue.",
            "La esperanza lo abandonó. Él no se dio cuenta.",
            "El peor de todos. Con orgullo.",
        ],
    ];

    private $database;

    public function __construct($database)
    {
        $this->database = $database;
    }

    // [usuario_id => ["muertes", "fechaMuertes", "ultimaFecha", "causa"]] de todos los que murieron
    // al menos una vez en Die. "fechaMuertes" = cuándo llegó a esa cantidad (para desempatar)
    // y "causa" = la categoría de la pregunta de su última muerte.
    public function muertes()
    {
        $filas = $this->database->queryAll(
            "SELECT pa.usuario_id, pa.fecha_fin, c.nombre AS categoria, pr.juego
             FROM partida pa
             JOIN partida_pregunta pp ON pp.partida_id = pa.id AND pp.acerto = 0
             JOIN pregunta pr ON pr.id = pp.pregunta_id
             JOIN categoria c ON c.id = pr.categoria_id
             WHERE pa.modo = 'die' AND pa.estado = 'terminada'
             ORDER BY pa.fecha_fin, pa.id"
        );

        $muertes = [];
        foreach ($filas as $fila) {
            $id = (int)$fila["usuario_id"];
            $muertes[$id]["muertes"] = ($muertes[$id]["muertes"] ?? 0) + 1;
            $muertes[$id]["fechaMuertes"] = $fila["fecha_fin"];
            $muertes[$id]["ultimaFecha"] = $fila["fecha_fin"];
            $muertes[$id]["causa"] = $fila["categoria"] . ($fila["juego"] ? " (" . $fila["juego"] . ")" : "");
        }
        return $muertes;
    }

    // Ordena a los jugadores por muertes (más primero; si empatan, el que llegó antes) y les pone
    // el puesto. Solo entran los que murieron al menos una vez.
    public static function ordenar(array $jugadores)
    {
        $lista = array_values(array_filter($jugadores, function ($jugador) {
            return ($jugador["muertes"]["muertes"] ?? 0) > 0;
        }));

        usort($lista, function ($a, $b) {
            return [$b["muertes"]["muertes"], $a["muertes"]["fechaMuertes"], $a["id"]]
                <=> [$a["muertes"]["muertes"], $b["muertes"]["fechaMuertes"], $b["id"]];
        });

        foreach ($lista as $i => $jugador) {
            $lista[$i]["puesto"] = $i + 1;
        }
        return $lista;
    }

    // Adorno tallado arriba de la lápida (public/img/cementerio/adorno-*.png), fijo por jugador.
    const ADORNOS = ["cruz", "calavera", "hoguera"];

    public static function adorno($usuarioId)
    {
        return self::ADORNOS[crc32("adorno-" . $usuarioId) % count(self::ADORNOS)];
    }

    // Frase de la lápida según cuántas veces murió. Dentro del tramo, la elige el id del jugador,
    // así cada uno tiene siempre la misma (cambia solo cuando pasa al tramo siguiente). Multiplicar
    // por 17 (que no comparte divisores con 50) salta por la lista sin repetir entre ids cercanos.
    public static function frase($usuarioId, $muertes)
    {
        foreach (self::TRAMOS as $i => $tramo) {
            if ($muertes >= $tramo[0] && $muertes <= $tramo[1]) {
                $frases = self::FRASES[$i];
                return $frases[((int)$usuarioId * 17) % count($frases)];
            }
        }
        return null;
    }
}
