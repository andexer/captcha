<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Captcha;
use Captcha\Config\ConfigFile;
use Captcha\Console\Doctor;
use Captcha\Console\DoctorReport;
use Captcha\Console\Finding;
use Captcha\Console\Severity;
use Captcha\Runtime\Host;
use PHPUnit\Framework\TestCase;

/**
 * El reporte de doctor es el único sitio donde se cuenta en voz alta cómo ve
 * el paquete su propia instalación, así que estos tests no comprueban que "no
 * revienta": comprueban que cada rama de la clasificación dice lo que debe
 * decir y con la gravedad que le corresponde.
 *
 * Se cubre lo que se puede controlar desde fuera —el config descubierto, el
 * host simulado, el storage efectivo y la postura de seguridad— y se deja
 * fuera lo que depende de la máquina donde corra: la versión de PHP, la
 * presencia de ext-gd, el drop-in del endpoint, un storage inyectado a mano y
 * las cinco cabeceras de framework (declarar un kernel falso en el proceso
 * contaminaría la detección de Host para el resto de la suite). Tampoco se
 * monta un ancla sin firma: los candidatos anclados cuelgan de directorios
 * por encima del paquete, y escribir en el árbol del usuario para probar un
 * aviso no es un precio que valga la pena pagar.
 */
final class DoctorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporales = [];

    /**
     * @var list<string>
     */
    private array $directorios = [];

    private ?string $cwd = null;

    protected function setUp(): void
    {
        /*
        *  Aislamiento: la capa estática y Host guardan estado entre pruebas, y
        *  un config o un directorio de trabajo heredados harían que este
        *  reporte describiera una instalación que no es la de ahora.
        */
        $this->cwd = getcwd();

        Captcha::reset();
        Captcha::configure([]);
        Host::forceFrameworkSession(null);
        Host::forceWeb(null);
        putenv('CAPTCHA_CONFIG');
    }

    protected function tearDown(): void
    {
        Captcha::reset();
        Captcha::configure([]);
        Host::forceFrameworkSession(null);
        Host::forceWeb(null);
        putenv('CAPTCHA_CONFIG');

        if (is_string($this->cwd)) {
            chdir($this->cwd);
        }

        foreach ($this->temporales as $path) {
            unlink($path);
        }

        $this->temporales = [];

        foreach ($this->directorios as $directory) {
            rmdir($directory . '/config');
            rmdir($directory);
        }

        $this->directorios = [];
    }

    public function testTheReportDescribesRuntimeConfigAndSummary(): void
    {
        $report = (new Doctor())->report();
        $text = self::flat($report);

        self::assertStringContainsString(
            extension_loaded('gd') ? 'ext-gd disponible' : 'ext-gd NO disponible',
            $text,
        );
        self::assertStringContainsString('Config efectivo', $text);
        self::assertStringContainsString('Resumen:', $text);
        self::assertNotNull($report->footer);
    }

    /**
     * Sin config la instalación funciona igual (con defaults), así que lo dice
     * como un aviso y no como un error: el comando no tumba.
     */
    public function testWithoutConfigTheReportInformsAndDoesNotFail(): void
    {
        Captcha::configure([]);

        $report = (new Doctor())->report();
        $text = self::flat($report);

        self::assertStringContainsString('Config: ninguno descubierto', $text);
        self::assertStringContainsString('defaults', $text);
        self::assertSame(Severity::Warning, $report->worst(), 'el honeypot apagado es el aviso más grave');
        self::assertSame(0, $report->exitCode(), 'un aviso no tumba el comando');
    }

    /*
    *  configure() manda y el reporte lo dice: el descubrimiento sigue
    *  sondeando sus candidatas, pero ninguna se atribuye lo que fijó la
    *  mano, y no se puede afirmar "ninguno descubierto" cuando sí hay
    *  opciones.
    */
    public function testManualOptionsAreReportedAsSuchAndShadeDiscovery(): void
    {
        $file = $this->tempConfig('<?php return ["length" => 9];');
        putenv('CAPTCHA_CONFIG=' . $file);
        Captcha::configure(['length' => 5]);

        $text = self::flat((new Doctor())->report());

        self::assertStringContainsString('opciones fijadas con Captcha::configure()', $text);
        self::assertStringContainsString('(no usado: gana Captcha::configure())', $text);
        self::assertStringNotContainsString('Config: ninguno descubierto', $text);
    }

    public function testTheDiscoveredConfigIsNamedAndItsPostureIsReported(): void
    {
        $file = $this->tempConfig(self::exportConfig([
            'length' => 7,
            'verifyAttempts' => 0,
            'generateAttempts' => 0,
            'honeypot' => true,
            'honeypotField' => 'sitio',
            'rateLimitByIp' => false,
            'trustedProxies' => ['203.0.113.5'],
            'storage' => 'array',
        ]));

        putenv('CAPTCHA_CONFIG=' . $file);

        $report = (new Doctor())->report();
        $text = self::flat($report);

        self::assertStringContainsString('Config descubierto: ' . $file, $text);
        self::assertStringContainsString('7 dígitos', $text);
        self::assertStringContainsString('cargado  ' . $file, $text);
        self::assertStringContainsString('Storage efectivo: memoria (ArrayStorage)', $text);
        self::assertStringContainsString('Rate limit de verificación DESACTIVADO', $text);
        self::assertStringContainsString('Rate limit de generación DESACTIVADO', $text);
        self::assertStringContainsString('Honeypot activo (campo "sitio")', $text);
        self::assertStringContainsString('Rate limit por IP: desactivado (solo sesión)', $text);
        self::assertStringContainsString('Proxies de confianza: 1 (203.0.113.5)', $text);
        self::assertSame(Severity::Warning, $report->worst());
        self::assertSame(0, $report->exitCode(), 'los diales apagados son avisos, no errores');
        self::assertSame(1, $report->exitCode(strict: true), 'en modo estricto los avisos sí tumban');
    }

    public function testTheDefaultLimitsAndProxiesAreReportedAsActive(): void
    {
        $text = self::flat((new Doctor())->report());

        self::assertStringContainsString('Rate limit de verificación: 5 intentos', $text);
        self::assertStringContainsString('Rate limit de generación: 20 captchas', $text);
        self::assertStringContainsString('Rate limit por IP: activo (dual IP + sesión)', $text);
        self::assertStringContainsString('Proxies de confianza: ninguno (X-Forwarded-For ignorado)', $text);
        self::assertStringContainsString('Honeypot desactivado', $text);
    }

    /**
     * Un host con framework propietario de la sesión es el único caso en el
     * que el almacenamiento automático evita session_start(), así que el
     * reporte tiene que decirlo junto al backend que resulted de ahí.
     */
    public function testAFrameworkHostIsReportedWithItsAutomaticFileStorage(): void
    {
        Host::forceFrameworkSession(true);
        Captcha::reset();

        $text = self::flat((new Doctor())->report());

        self::assertStringContainsString('Host: framework propietario de la sesión', $text);
        self::assertStringContainsString('Storage efectivo: archivos (FileStorage)', $text);
    }

    public function testAPlainHostIsReportedWithSessionStorage(): void
    {
        $text = self::flat((new Doctor())->report());

        self::assertStringContainsString('Host: PHP plano / CLI (auto-storage = sesión)', $text);
        self::assertStringContainsString('Storage efectivo: sesión (SessionStorage)', $text);
    }

    public function testAConfigThatBreaksTheFacadeIsAnError(): void
    {
        putenv('CAPTCHA_CONFIG=' . $this->tempConfig('<?php return "no-array";'));

        $report = (new Doctor())->report();

        self::assertStringContainsString('La fachada no arranca con este config', self::flat($report));
        self::assertSame(Severity::Error, $report->worst());
        self::assertSame(1, $report->exitCode());
    }

    /**
     * La transparencia del descubrimiento: un config que existe pero no gana la
     * precedencia se reporta como sombreado, no se esconde.
     */
    public function testAConfigShadowedByAnEarlierCandidateIsReported(): void
    {
        $ganador = $this->tempConfig('<?php return ["length" => 6];');
        $sombreado = $this->tempConfigInCwd('<?php return ["length" => 9];');

        putenv('CAPTCHA_CONFIG=' . $ganador);

        $text = self::flat((new Doctor())->report());

        self::assertStringContainsString('cargado  ' . $ganador, $text);
        self::assertStringContainsString('válido   ' . $sombreado, $text);
        self::assertStringContainsString('sombreado por una candidatura anterior', $text);
    }

    /**
     * Una CAPTCHA_CONFIG rota no se confunde con "no hay config": en runtime
     * el descubrimiento la salta y sigue con las anclas (silencio a
     * propósito), pero si el reporte la calla el typo acaba leyéndose como
     * una instalación que eligió defaults, que es justo lo contrario de lo
     * que pasó.
     */
    public function testABrokenEnvironmentVariableIsNamedAndGradedAsWarning(): void
    {
        putenv('CAPTCHA_CONFIG=' . sys_get_temp_dir() . '/captcha-que-no-existe.php');
        Captcha::configure([]);

        $report = (new Doctor())->report();
        $text = self::flat($report);

        self::assertStringContainsString('CAPTCHA_CONFIG apunta a ' . sys_get_temp_dir() . '/captcha-que-no-existe.php', $text);
        self::assertStringContainsString('el descubrimiento la salta', $text);
        self::assertStringNotContainsString('Config descubierto: ', $text, 'el fichero no existe, no se descubre nada');

        $rotos = array_values(array_filter(
            $report->findings,
            static fn(Finding $finding): bool => str_contains($finding->text, 'CAPTCHA_CONFIG apunta a'),
        ));

        self::assertCount(1, $rotos);
        self::assertSame(Severity::Warning, $rotos[0]->severity, 'una env rota es aviso, no error');
        self::assertSame(1, $report->exitCode(strict: true), 'en --strict la env rota sí tumba');
    }

    /**
     * Una env presente pero vacía tampoco es una env ausente: la variable está
     * puesta, así que se reporta como intención rota y no se disfraza de
     * "ninguno descubierto".
     */
    public function testAnEmptyEnvironmentVariableIsReportedAsSuch(): void
    {
        putenv('CAPTCHA_CONFIG=');
        Captcha::configure([]);

        $text = self::flat((new Doctor())->report());

        self::assertStringContainsString('CAPTCHA_CONFIG está vacía', $text);
        self::assertStringContainsString('Config: ninguno descubierto', $text);
    }

    /**
     * Un candidato del directorio de trabajo se acepta con o sin firma; solo
     * los anclados la exigen.
     */
    public function testACwdConfigIsAcceptedWithoutSignature(): void
    {
        $path = $this->tempConfigInCwd('<?php return ["length" => 8];');
        $text = self::flat((new Doctor())->report());

        self::assertStringContainsString('Config descubierto: ' . $path, $text);
        self::assertStringContainsString('8 dígitos', $text);
    }

    public function testASignedCwdConfigCarriesThePackageMarker(): void
    {
        $path = $this->tempConfigInCwd(
            sprintf("<?php\n\n// %s\n\nreturn ['length' => 4];", ConfigFile::SIGNATURE),
        );

        self::assertTrue(ConfigFile::carriesSignature($path));
        self::assertStringContainsString('cargado  ' . $path, self::flat((new Doctor())->report()));
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function exportConfig(array $options): string
    {
        return sprintf(
            "<?php\n\n// %s\n\nreturn %s;\n",
            ConfigFile::SIGNATURE,
            var_export($options, true),
        );
    }

    private function tempConfig(string $contents): string
    {
        return $this->write(sys_get_temp_dir() . '/captcha-doctor-' . bin2hex(random_bytes(6)) . '.php', $contents);
    }

    private function tempConfigInCwd(string $contents): string
    {
        $directory = sys_get_temp_dir() . '/captcha-doctor-cwd-' . bin2hex(random_bytes(6));

        mkdir($directory . '/config', 0o775, true);
        chdir($directory);

        $this->directorios[] = $directory;

        return $this->write($directory . '/config/captcha.php', $contents);
    }

    private function write(string $path, string $contents): string
    {
        file_put_contents($path, $contents);
        $this->temporales[] = $path;

        return $path;
    }

    private static function flat(DoctorReport $report): string
    {
        return implode("\n", array_map(
            static fn($finding): string => $finding->text,
            $report->findings,
        ));
    }
}
