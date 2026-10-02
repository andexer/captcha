<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Console\DoctorReport;
use Captcha\Console\Finding;
use Captcha\Console\Severity;
use Captcha\Console\Style;
use PHPUnit\Framework\TestCase;

final class DoctorReportTest extends TestCase
{
    public function testAnEmptyReportSucceeds(): void
    {
        $reporte = new DoctorReport([], 'vacío');

        self::assertSame(0, $reporte->exitCode());
        self::assertSame(0, $reporte->exitCode(true));
        self::assertNull($reporte->worst());
    }

    public function testOnlyOkSucceeds(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Ok, 'todo bien')]);

        self::assertSame(0, $reporte->exitCode());
        self::assertSame(0, $reporte->exitCode(true));
    }

    /**
     * Un aviso no tumba el proceso salvo en modo estricto: casi siempre es una
     * decisión deliberada de quien integra, y doctor tiene que poder usarse
     * en un despliegue donde eso es lo esperado.
     */
    public function testAWarningPassesByDefaultAndFailsInStrictMode(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Warning, 'honeypot apagado')]);

        self::assertSame(0, $reporte->exitCode());
        self::assertSame(1, $reporte->exitCode(true));
    }

    public function testANoticeNeverFailsEvenInStrictMode(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Notice, 'sin config')]);

        self::assertSame(0, $reporte->exitCode(true));
    }

    public function testAnErrorAlwaysFails(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Error, 'falta ext-gd')]);

        self::assertSame(1, $reporte->exitCode());
        self::assertSame(1, $reporte->exitCode(true));
    }

    public function testWorstIgnoresTheOrderOfTheFindings(): void
    {
        $reporte = new DoctorReport([
            new Finding(Severity::Warning, 'aviso'),
            new Finding(Severity::Error, 'error'),
            new Finding(Severity::Ok, 'bien'),
        ]);

        self::assertSame(Severity::Error, $reporte->worst());
    }

    public function testCountsGroupsBySeverity(): void
    {
        $reporte = new DoctorReport([
            new Finding(Severity::Ok, 'a'),
            new Finding(Severity::Ok, 'b'),
            new Finding(Severity::Error, 'c'),
        ]);

        self::assertSame(['ok' => 2, 'error' => 1], $reporte->counts());
    }

    public function testRenderPutsTheMarkerAndTheTextOnTheSameLine(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Ok, 'ext-gd disponible')]);
        $lineas = $reporte->render(Style::plain());

        self::assertSame('vacío', 'vacío');
        self::assertStringContainsString('[ok] ext-gd disponible', implode("\n", $lineas));
    }

    public function testRenderIndentsDetailsUnderTheirParent(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Ok, 'detalle', 1)]);
        $texto = implode("\n", $reporte->render(Style::plain()));

        // El hueco es de 1 espacio base más 4 por nivel de anidamiento.
        self::assertStringContainsString('[ok]' . str_repeat(' ', 5) . 'detalle', $texto);
    }

    public function testRenderEmitsNoEscapeCodesWithAPlainStyle(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Error, 'roto')]);

        self::assertStringNotContainsString("\033", implode("\n", $reporte->render(Style::plain())));
    }

    public function testRenderAtLeastDropsTheLighterFindings(): void
    {
        $reporte = new DoctorReport([
            new Finding(Severity::Ok, 'bien'),
            new Finding(Severity::Notice, 'sin config'),
            new Finding(Severity::Error, 'roto'),
        ]);

        $texto = implode("\n", $reporte->renderAtLeast(Severity::Error, Style::plain()));

        self::assertStringContainsString('roto', $texto);
        self::assertStringNotContainsString('bien', $texto);
        self::assertStringNotContainsString('sin config', $texto);
    }

    public function testRenderAtLeastKeepsTheTitleSoSilenceIsReadable(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Ok, 'bien')], 'cabecera');
        $lineas = $reporte->renderAtLeast(Severity::Error, Style::plain());

        self::assertSame(['cabecera'], $lineas);
    }

    public function testTheFooterIsOmittedWhenNothingIsVisible(): void
    {
        $reporte = new DoctorReport([new Finding(Severity::Ok, 'bien')], 'cabecera', 'siguientes pasos');

        self::assertStringNotContainsString('siguientes pasos', implode("\n", $reporte->renderAtLeast(Severity::Error, Style::plain())));
        self::assertStringContainsString('siguientes pasos', implode("\n", $reporte->render(Style::plain())));
    }

    public function testSeverityRanksAreOrderedFromOkToError(): void
    {
        self::assertGreaterThan(Severity::Warning->rank(), Severity::Error->rank());
        self::assertGreaterThan(Severity::Notice->rank(), Severity::Warning->rank());
        self::assertGreaterThan(Severity::Ok->rank(), Severity::Notice->rank());
    }

    public function testWorstOnAnEmptySetIsNull(): void
    {
        self::assertNull(Severity::worst([]));
    }

    public function testWorstPicksTheMostSevere(): void
    {
        self::assertSame(Severity::Error, Severity::worst([Severity::Ok, Severity::Error, Severity::Notice]));
    }

    public function testEverySeverityHasAMarkerAndALabel(): void
    {
        foreach (Severity::cases() as $severidad) {
            self::assertNotSame('', $severidad->marker(), $severidad->value);
            self::assertNotSame('', $severidad->label(), $severidad->value);
        }
    }
}
