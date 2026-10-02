<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Generación de la ayuda a partir del catálogo de comandos.
 *
 * No hay ninguna cadena de ayuda escrita a mano en el paquete. Todo se
 * compone desde Registry, así que una opción nueva aparece en `list`, en
 * `help install` y en el error de "opción desconocida" con el mismo texto, y
 * es imposible que el manual acepte algo que la CLI rechaza. El texto
 * largo de cada comando viene de Command::$description, y el ancho de las
 * columnas se calcula, no se alinea a ojo.
 *
 * @internal
 */
final class Usage
{
    /**
     * Cómo se llama al ejecutable en los ejemplos.
     */
    public const BIN = 'vendor/bin/captcha';

    /**
     * Sangría de las descripciones largas respecto al margen izquierdo.
     */
    private const SANGRIA = '  ';

    /**
     * La ayuda general: comandos, opciones globales y ejemplos.
     *
     * @return list<string>
     */
    public static function overview(Style $style): array
    {
        return [
            ...self::overviewHeader($style),
            ...self::bloque('Comandos:', self::columnas(self::commandRows(), $style), $style),
            ...self::bloque('Opciones globales:', self::opcionesDe(Registry::globalOptions(), $style), $style),
            ...self::bloque('Ejemplos:', self::overviewExamples($style), $style),
            '',
            $style->dim('Más ayuda: ' . self::BIN . ' help <comando>'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function overviewHeader(Style $style): array
    {
        return [
            $style->heading('captcha — consola del paquete'),
            '',
            $style->accent('Uso:'),
            self::SANGRIA . self::BIN . ' <comando> [opciones]',
        ];
    }

    /**
     * Una fila por comando: nombre, resumen y alias entre corchetes.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function commandRows(): array
    {
        $filas = [];

        foreach (Registry::commands() as $command) {
            $alias = $command->aliases === [] ? '' : '[' . implode(', ', $command->aliases) . ']';
            $filas[] = [$command->name, $command->summary, $alias];
        }

        return $filas;
    }

    /**
     * @return list<string>
     */
    private static function overviewExamples(Style $style): array
    {
        $filas = [];

        foreach (['install laravel', 'doctor --strict', 'help install'] as $ejemplo) {
            $filas[] = self::SANGRIA . $style->dim(self::BIN . ' ' . $ejemplo);
        }

        return $filas;
    }

    /**
     * La ayuda de un comando concreto.
     *
     * @return list<string>
     */
    public static function command(Command $command, Style $style): array
    {
        return [
            ...self::encabezado($command, $style),
            ...self::descripcion($command, $style),
            ...self::argumentos($command, $style),
            ...self::opciones($command, $style),
            ...self::ejemplos($command, $style),
        ];
    }

    /**
     * @return list<string>
     */
    private static function encabezado(Command $command, Style $style): array
    {
        $lineas = [
            $style->heading(self::BIN . ' ' . $command->name . ' — ' . $command->summary),
            '',
            $style->accent('Uso:'),
            self::SANGRIA . self::synopsis($command),
        ];

        if ($command->aliases !== []) {
            $lineas[] = self::SANGRIA . $style->dim('Alias: ' . implode(', ', $command->aliases));
        }

        return $lineas;
    }

    /**
     * @return list<string>
     */
    private static function descripcion(Command $command, Style $style): array
    {
        $lineas = [''];

        foreach (explode("\n", $command->description) as $parrafo) {
            $lineas[] = $parrafo === '' ? '' : self::SANGRIA . $parrafo;
        }

        return $lineas;
    }

    /**
     * @return list<string>
     */
    private static function argumentos(Command $command, Style $style): array
    {
        if ($command->arguments === []) {
            return [];
        }

        $filas = [];

        foreach ($command->arguments as $argumento) {
            $filas[] = [$argumento->synopsis(), $argumento->description];
        }

        return self::bloque('Argumentos:', self::columnas($filas, $style), $style);
    }

    /**
     * @return list<string>
     */
    private static function opciones(Command $command, Style $style): array
    {
        $opciones = $command->allOptions();

        if ($opciones === []) {
            return [];
        }

        return self::bloque('Opciones:', self::opcionesDe($opciones, $style), $style);
    }

    /**
     * @return list<string>
     */
    private static function ejemplos(Command $command, Style $style): array
    {
        if ($command->examples === []) {
            return [];
        }

        $filas = [];

        foreach ($command->examples as $ejemplo) {
            $filas[] = self::SANGRIA . $style->dim($ejemplo);
        }

        return self::bloque('Ejemplos:', $filas, $style);
    }

    /**
     * Rótulo de sección seguido de sus líneas, con la línea en blanco que las
     * separa de lo anterior.
     *
     * @param list<string> $lineas
     *
     * @return list<string>
     */
    private static function bloque(string $titulo, array $lineas, Style $style): array
    {
        return ['', $style->accent($titulo), ...$lineas];
    }

    /**
     * La línea de uso de un comando: nombre, argumentos y opciones propias.
     *
     * Las opciones globales no aparecen aquí a propósito. Van en su propio
     * bloque, unas líneas más abajo, y meterlas en el uso produce una línea
     * tan larga que hay que partirla para leerla, que es justo lo contrario
     * de lo que sirve una línea de uso.
     */
    public static function synopsis(Command $command): string
    {
        $partes = [self::BIN, $command->name];

        foreach ($command->arguments as $argumento) {
            $partes[] = $argumento->synopsis();
        }

        foreach ($command->options as $opcion) {
            $partes[] = '[' . $opcion->compact() . ']';
        }

        return implode(' ', $partes);
    }

    /**
     * Las opciones en dos columnas, con la alineación calculada.
     *
     * @param list<Option> $opciones
     *
     * @return list<string>
     */
    private static function opcionesDe(array $opciones, Style $style): array
    {
        $filas = [];

        foreach ($opciones as $opcion) {
            $filas[] = [$opcion->synopsis(), $opcion->description];
        }

        return self::columnas($filas, $style);
    }

    /**
     * Imprime filas de "columna izquierda | columna derecha" alineadas.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $filas
     *
     * @return list<string>
     */
    private static function columnas(array $filas, Style $style): array
    {
        if ($filas === []) {
            return [];
        }

        $ancho = self::columnWidth($filas);
        $lineas = [];

        foreach ($filas as $fila) {
            $lineas[] = self::column($fila, $ancho, $style);
        }

        return $lineas;
    }

    /**
     * Ancho de la columna izquierda, en caracteres multibyte: dos columnas
     * desalineadas se leen peor que una línea larga.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $filas
     */
    private static function columnWidth(array $filas): int
    {
        $ancho = 0;

        foreach ($filas as $fila) {
            $ancho = max($ancho, mb_strlen($fila[0], 'UTF-8'));
        }

        return $ancho;
    }

    /**
     * Una fila ya alineada; el tercer campo es un apéndice opcional.
     *
     * @param array{0: string, 1: string, 2?: string} $fila
     */
    private static function column(array $fila, int $ancho, Style $style): string
    {
        $hueco = str_repeat(' ', $ancho - mb_strlen($fila[0], 'UTF-8') + 2);
        $linea = self::SANGRIA . $style->success($fila[0]) . $hueco . $style->dim($fila[1]);

        if (isset($fila[2]) && $fila[2] !== '') {
            $linea .= '  ' . $style->accent($fila[2]);
        }

        return $linea;
    }
}
