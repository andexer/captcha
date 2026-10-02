<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Runtime;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Exception\InvalidConfigException;
use Captcha\Runtime\Host;
use Captcha\Runtime\StaticLayer;
use Captcha\Storage\FileStorage;
use Captcha\Storage\SessionStorage;
use PHPUnit\Framework\TestCase;

/**
 * La red de la capa estática contra la que está construida la extracción
 * #2: la API pública (Captcha::instance/configure/reset/configPath + los
 * métodos de flujo) debe conservar su semántica exacta — singleton por
 * petición, opciones que sobreviven a reset(), orden de descubrimiento env
 * → anclas de raíz firmadas → cwd.
 *
 * Los tests de layout anclado son relativos por diseño: la suite nunca
 * asume "que no exista config en ninguna parte" (un checkout anidado resuelve
 * legítimamente su ancla de raíz), así que afirman la pertenencia a la lista
 * de candidatos y la decisión de aceptar/rechazar, saltándose con gracia
 * cuando el layout no se puede reproducir desde el directorio del paquete.
 */
final class StaticLayerTest extends TestCase
{
    private ?string $configFile = null;

    private static ?string $baselineConfigFile = null;

    public static function setUpBeforeClass(): void
    {
        /*
        *  Baseline de "sin configuración": un config vacío explícito. Los
        *  tests que afirman los defaults del paquete no deben depender de lo
        *  que haya alrededor del directorio del paquete: en un checkout
        *  anidado el ancla de raíz resuelve legítimamente el
        *  app/Config/captcha.php firmado del HOST, y esos valores se colarían
        *  en aserciones como assertSame(Config::DEFAULT_LENGTH, ...). El
        *  candidato por env gana a cualquier ancla, así que pinearlo aquí hace
        *  el descubrimiento determinista se ejecute la suite desde donde se
        *  ejecute.
        */
        $file = (string) tempnam(sys_get_temp_dir(), 'captcha-staticlayer-baseline-');
        file_put_contents($file, '<?php return [];');
        self::$baselineConfigFile = $file;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$baselineConfigFile !== null) {
            @unlink(self::$baselineConfigFile);
            putenv('CAPTCHA_CONFIG');
            self::$baselineConfigFile = null;
        }
    }

    protected function setUp(): void
    {
        Captcha::reset();
        Captcha::configure([]);

        if (self::$baselineConfigFile !== null) {
            putenv('CAPTCHA_CONFIG=' . self::$baselineConfigFile);
        }
    }

    protected function tearDown(): void
    {
        Host::forceFrameworkSession(null);
        Captcha::reset();
        Captcha::configure([]);
        putenv('CAPTCHA_CONFIG');

        if ($this->configFile !== null) {
            @unlink($this->configFile);
            $this->configFile = null;
        }
    }

    public function testInstanceIsLazilyBuiltAndReusedWithinTheRequest(): void
    {
        $first = Captcha::instance();
        $second = Captcha::instance();

        self::assertSame($first, $second);
        self::assertSame(Config::DEFAULT_LENGTH, $first->config()->length);
    }

    public function testResetDiscardsTheSingletonButKeepsManualOptions(): void
    {
        Captcha::configure(['length' => 8]);
        $first = Captcha::instance();
        Captcha::reset();
        $second = Captcha::instance();

        self::assertNotSame($first, $second);
        self::assertSame(8, $second->config()->length);
    }

    public function testConfigureReplacesDiscoveryUntilReleased(): void
    {
        Captcha::configure(['length' => 4, 'ttl' => 60]);
        self::assertSame(4, Captcha::instance()->config()->length);

        Captcha::configure([]);
        self::assertSame(Config::DEFAULT_LENGTH, Captcha::instance()->config()->length);
    }

    public function testConfigureAcceptsAConfigInstanceAsIs(): void
    {
        $config = Config::forStrict();
        Captcha::configure($config);

        self::assertSame($config, Captcha::instance()->config());
    }

    public function testConfigPathIsNullOrTheDiscoveredFile(): void
    {
        Captcha::configure(['length' => 4]);
        self::assertNull(Captcha::configPath(), 'Las opciones manuales responden null sin tocar el disco');

        Captcha::configure([]);
        $file = $this->tempConfig('<?php return ["length" => 7];');
        putenv('CAPTCHA_CONFIG=' . $file);
        self::assertSame($file, Captcha::configPath());
    }

    public function testManualOptionsWinOverDiscovery(): void
    {
        $file = $this->tempConfig('<?php return ["length" => 7];');
        putenv('CAPTCHA_CONFIG=' . $file);
        Captcha::configure(['length' => 4]);

        self::assertSame(4, Captcha::instance()->config()->length);
    }

    public function testEnvironmentConfigLoads(): void
    {
        $file = $this->tempConfig('<?php return ["length" => 8, "ttl" => 60];');
        putenv('CAPTCHA_CONFIG=' . $file);

        $config = Captcha::instance()->config();

        self::assertSame(8, $config->length);
        self::assertSame(60, $config->ttl);
    }

    public function testBrokenConfigFileThrowsInvalidConfig(): void
    {
        $file = $this->tempConfig('<?php return "no-array";');
        putenv('CAPTCHA_CONFIG=' . $file);

        $this->expectException(InvalidConfigException::class);

        Captcha::instance();
    }

    /**
     * Las anclas de raíz siempre incluyen los niveles del propio árbol del
     * paquete; la lista de candidatos debe exponer exactamente esas rutas
     * ancladas (3 y 4 niveles hacia arriba, deduplicadas), que es lo que
     * protege la puerta de firma.
     */
    public function testCandidatesExposeAnchoredAndExplicitPaths(): void
    {
        $candidates = StaticLayer::candidatesForDiagnostics();

        self::assertNotEmpty($candidates);

        foreach ($candidates as $candidate) {
            self::assertArrayHasKey('path', $candidate);
            self::assertArrayHasKey('anchored', $candidate);
            self::assertArrayHasKey('loaded', $candidate);
            self::assertArrayHasKey('signed', $candidate);
        }

        /*
        *  Sin env y sin opciones manuales: lo cargado, si lo hay, debe ser
        *  una candidatura del ancla (con firma) o del cwd (sin firma).
        */
        $winner = Captcha::configPath();

        if ($winner !== null) {
            $loaded = array_values(array_filter($candidates, static fn(array $c): bool => $c['loaded']));
            self::assertCount(1, $loaded);
            self::assertSame($winner, $loaded[0]['path']);
        }
    }

    public function testAutoStorageResolvesPerHostOutsideTheFacade(): void
    {
        Host::forceFrameworkSession(false);
        Captcha::configure(['storage' => 'auto']);
        Captcha::reset();

        self::assertInstanceOf(SessionStorage::class, Captcha::instance()->storage());

        Host::forceFrameworkSession(true);
        Captcha::reset();

        self::assertInstanceOf(FileStorage::class, Captcha::instance()->storage());
    }

    private function tempConfig(string $body): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'captcha-staticlayer-');
        file_put_contents($file, $body);
        $this->configFile = $file;

        return $file;
    }
}
