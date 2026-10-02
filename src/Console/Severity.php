<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Cuán grave es una línea del reporte de doctor.
 *
 * El orden del enum es el orden de gravedad, y no un detalle: `worst()`
 * recorre los casos de mayor a menor y se apoya en ese orden para quedarse
 * con el peor de todos. Por eso el caso más grave se declara el último y no
 * el primero, y por eso nadie debe reordenar los casos sin revisar `worst()`.
 *
 * @internal
 */
enum Severity: string
{
    case Ok = 'ok';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * Marcador corto que encabeza la línea, al estilo de las herramientas de
     * línea de comandos: dos caracteres o menos para que la columna sea
     * legible en una terminal estrecha.
     */
    public function marker(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Notice => '..',
            self::Warning => '!!',
            self::Error => 'XX',
        };
    }

    /**
     * Etiqueta larga, para salidas donde una abreviatura de dos caracteres
     * se pierde: se reserva a `--format` o a un informe para humanos.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'correcto',
            self::Notice => 'aviso informativo',
            self::Warning => 'aviso',
            self::Error => 'error',
        };
    }

    /**
     * La severidad más grave de un conjunto, o null si está vacío.
     *
     * @param iterable<Severity> $severities
     */
    public static function worst(iterable $severities): ?self
    {
        $peor = null;

        foreach ($severities as $severity) {
            if ($peor === null || $severity->rank() > $peor->rank()) {
                $peor = $severity;
            }
        }

        return $peor;
    }

    /**
     * Rango numérico de gravedad; 0 es lo más leve.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Notice => 1,
            self::Warning => 2,
            self::Error => 3,
        };
    }
}
