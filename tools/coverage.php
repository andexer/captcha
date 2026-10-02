<?php

declare(strict_types=1);

/**
 * Puerta de cobertura de producción: lee el Clover que genera PHPUnit y
 * termina con 1 si el porcentaje de líneas ejecutadas no llega al umbral.
 *
 * Motivo de existir: PHPUnit genera el informe pero no trae umbral propio, así
 * que sin este script la cifra es un número que nadie mira y que se puede
 * degradar en silencio. Aquí se compara con el mínimo pactado y el proceso
 * avisa antes de caer.
 *
 * El filtro de qué es producción vive en phpunit.xml.dist (<source>), no
 * aquí: duplicarlo en dos sitios acabaría midiendo cifras distintas según quién
 * mire. tests/Unit/CoverageScopeTest.php vigila que ese filtro siga apuntando a
 * directorios que existen.
 *
 * Uso: php tools/coverage.php [ruta clover] [umbral]
 *      php tools/coverage.php coverage/clover.xml 90
 * El umbral es un porcentaje de líneas y admite decimales.
 *
 * Salidas: 0 si llega al umbral, 1 si no llega, 2 si la entrada no sirve.
 */

$raiz = dirname(__DIR__);
$ruta = $argv[1] ?? $raiz . '/coverage/clover.xml';
$umbral = isset($argv[2]) ? filter_var($argv[2], FILTER_VALIDATE_FLOAT) : 90.0;

/*
 *  El informe viene con rutas absolutas de la máquina donde se generó; el
 *  informe se lee en un pull request, así que se recortan a rutas del paquete.
 */
$relativa = static function (string $nombre) use ($raiz): string {
    return str_starts_with($nombre, $raiz . '/') ? substr($nombre, strlen($raiz) + 1) : $nombre;
};

if ($umbral === false || $umbral > 100.0 || $umbral < 0.0) {
    fwrite(STDERR, sprintf("El umbral debe ser un porcentaje entre 0 y 100, no '%s'.\n", $argv[2] ?? ''));

    exit(2);
}

if (!is_file($ruta)) {
    fwrite(STDERR, sprintf(
        "No existe el informe '%s'. Genéralo antes con --coverage-clover.\n",
        $ruta,
    ));

    exit(2);
}

if (!function_exists('simplexml_load_file')) {
    fwrite(STDERR, "Falta ext-simplexml, necesaria para leer el informe Clover.\n");

    exit(2);
}

/*
 *  Los errores de libxml se acumulan para poder dar un mensaje propio: sueltos
 * Salen como avisos de PHP con el número de línea de este script, que a quien
 *  ejecuta la puerta no le dice nada sobre el fichero que está roto.
 */
libxml_use_internal_errors(true);
$clover = simplexml_load_file($ruta);
libxml_clear_errors();

if ($clover === false) {
    fwrite(STDERR, sprintf("El informe '%s' no es un XML legible.\n", $ruta));

    exit(2);
}

/*
 *  Cada fichero del informe trae sus líneas ejecutables con un contador de
 *  visitas: cuenta cero significa que nadie pasó por ahí. Un fichero con
 *  líneas pero ninguna visita no es un fichero poco cubierto, es un fichero que
 *  el paquete no llega a cargar, y por eso se avisa de él en lugar de diluirlo
 *  en la media. Los que no tienen ninguna línea ejecutable —interfaces y
 *  enums— se saltan: no hay nada que medir en ellos.
 *
 *  Los ficheros se buscan con xpath y no con $clover->project->file porque
 *  Clover agrupa por paquete: los de espacio de nombres caen en <package> y
 *  están un nivel más abajo que los demás.
 */
$nodos = $clover->xpath('//file');

if ($nodos === false) {
    fwrite(STDERR, sprintf("El informe '%s' no tiene ficheros que medir.\n", $ruta));

    exit(2);
}

$ficheros = [];
$vivas = 0;
$muertas = 0;

foreach ($nodos as $fichero) {
    $nombre = (string) $fichero['name'];
    $vivasFichero = 0;
    $muertasFichero = 0;

    foreach ($fichero->line as $linea) {
        if ((string) $linea['type'] !== 'stmt') {
            continue;
        }

        if ((int) $linea['count'] > 0) {
            $vivasFichero++;
        } else {
            $muertasFichero++;
        }
    }

    if ($vivasFichero + $muertasFichero === 0) {
        continue;
    }

    if ($vivasFichero === 0) {
        fwrite(STDERR, sprintf(
            "::error::%s no tiene ni una línea ejecutada: el paquete no llega a cargarlo.\n",
            $relativa($nombre),
        ));
    }

    $ficheros[$relativa($nombre)] = [$vivasFichero, $muertasFichero];
    $vivas += $vivasFichero;
    $muertas += $muertasFichero;
}

if (($vivas + $muertas) === 0) {
    fwrite(STDERR, sprintf("El informe '%s' no tiene líneas ejecutables que medir.\n", $ruta));

    exit(2);
}

$total = $vivas + $muertas;
$porcentaje = 100 * $vivas / $total;

printf(
    "Cobertura de producción: %d/%d líneas = %.2f%% (umbral %.2f%%)\n",
    $vivas,
    $total,
    $porcentaje,
    $umbral,
);

/*
 *  Los peores ficheros son la lista de trabajo: sin ellos la cifra solo dice
 *  cuánto falta, no dónde. El orden es por porcentaje y no por líneas sin
 *  ejecutar, porque un fichero de cinco líneas con un hueco es peor noticia que
 *  uno de doscientas con treinta. El corte evita convertir la salida en un
 *  volcado.
 */
$porOrden = [];

foreach ($ficheros as $nombre => [$vivasFichero, $muertasFichero]) {
    if ($muertasFichero === 0) {
        continue;
    }

    $porOrden[] = [100 * $vivasFichero / ($vivasFichero + $muertasFichero), $nombre, $vivasFichero];
}

usort($porOrden, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

foreach (array_slice($porOrden, 0, 10) as [$porcentajeFichero, $nombre, $vivasFichero]) {
    printf(
        "  %6.1f%%  %4d/%-4d  %s\n",
        $porcentajeFichero,
        $vivasFichero,
        $ficheros[$nombre][0] + $ficheros[$nombre][1],
        $nombre,
    );
}

if (count($porOrden) > 10) {
    printf("  … y %d fichero(s) más con líneas sin ejecutar.\n", count($porOrden) - 10);
}

exit($porcentaje >= $umbral ? 0 : 1);
