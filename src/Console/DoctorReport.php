<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Resultado completo de `captcha doctor`.
 *
 * Es un dato, no un texto: guarda los hallazgos con su gravedad y solo
 * entonces sabe decidir cómo terminar. Eso es lo que faltaba para que el
 * comando sirviera en un script de despliegue, donde un chequeo que siempre
 * sale con 0 no comprueba nada.
 *
 * El renderizado se pide aparte y se lleva un Style, de modo que el mismo
 * reporte sirve para una terminal de colores y para un fichero de texto sin
 * cambiar una línea de la salida.
 *
 * @internal
 */
final readonly class DoctorReport
{
    /**
     * @param list<Finding> $findings Hallazgos, en el orden en que se comprobaron.
     * @param string $title Cabecera del reporte.
     * @param string|null $footer Cierre con los siguientes pasos, si aplica.
     */
    public function __construct(
        public array $findings,
        public string $title = 'captcha — doctor del entorno',
        public ?string $footer = null,
    ) {}

    /**
     * La gravedad más grave presente, o null si no hay hallazgos.
     */
    public function worst(): ?Severity
    {
        return Severity::worst(array_map(
            static fn(Finding $finding): Severity => $finding->severity,
            $this->findings,
        ));
    }

    /**
     * Código de salida del comando.
     *
     * Un error siempre tumba el proceso: sin ext-gd o con la fachada rota el
     * captcha no funciona y no tiene sentido continuar. Un aviso solo lo hace
     * en modo estricto, porque casi siempre es una decisión deliberada de
     * quien integra —honeypot apagado en una app que no expone formularios,
     * host CLI en un script de despliegue— y hacerlos fatales por defecto
     * convertiría doctor en una puerta que siempre está cerrada.
     */
    public function exitCode(bool $strict = false): int
    {
        $peor = $this->worst();

        if ($peor === null) {
            return 0;
        }

        if ($peor === Severity::Error) {
            return 1;
        }

        return $strict && $peor->rank() >= Severity::Warning->rank() ? 1 : 0;
    }

    /**
     * Cuántos hallazgos hay de cada gravedad, para un resumen final.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->findings as $finding) {
            $clave = $finding->severity->value;
            $counts[$clave] = ($counts[$clave] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Las líneas que se imprimen, con color si el estilo lo permite.
     *
     * @return list<string>
     */
    public function render(Style $style): array
    {
        return $this->renderAtLeast(Severity::Ok, $style);
    }

    /**
     * Lo mismo, pero dejando fuera todo lo más leve que la severidad indicada.
     *
     * Es lo que necesita `--quiet`: un despliegue que solo quiere saber si
     * algo está roto no necesita volver a leer doce líneas sobre la versión de
     * GD para descubrir que todo va bien. El encabezado se conserva siempre,
     * porque una salida vacía no se distingue de un fallo de la propia consola.
     *
     * @return list<string>
     */
    public function renderAtLeast(Severity $severidad, Style $style): array
    {
        $lines = [$style->heading($this->title)];
        $visibles = 0;

        foreach ($this->findings as $finding) {
            if ($finding->severity->rank() < $severidad->rank()) {
                continue;
            }
            $visibles++;
            $lines[] = $this->renderFinding($finding, $style);
        }

        return [...$lines, ...$this->pie($visibles > 0, $style)];
    }

    /**
     * El pie solo se enseña si se ha enseñado algo: un reporte sin hallazgos
     * cuelga de un cierre que no explica nada.
     *
     * @return list<string>
     */
    private function pie(bool $hayVisibles, Style $style): array
    {
        if (!$hayVisibles || $this->footer === null) {
            return [];
        }

        return ['', $style->dim($this->footer)];
    }

    /**
     * Marcador, sangría y color de un hallazgo.
     *
     * Aviso y advertencia comparten color a propósito: en un despliegue lo
     * que importa es que la línea salte, no distinguir dos grados que el
     * --strict ya convierte en el mismo código de salida.
     */
    private function renderFinding(Finding $finding, Style $style): string
    {
        $marcador = sprintf('[%s]', $finding->severity->marker());
        $hueco = str_repeat(' ', 1 + max(0, $finding->indent) * 4);
        $linea = $marcador . $hueco . $finding->text;

        return match ($finding->severity) {
            Severity::Ok => $style->success($linea),
            Severity::Notice, Severity::Warning => $style->warning($linea),
            Severity::Error => $style->failure($linea),
        };
    }
}
