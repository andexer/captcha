<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Exception\InvalidConfigException;
use Captcha\Verification\Status;
use PHPUnit\Framework\TestCase;

final class CaptchaStaticTest extends TestCase
{
    /*
    *  Config hermético de la clase: un array vacío servido por la variable de
    *  entorno, para que la suite no dependa de los ficheros de configuración
    *  que existan en el repo (p. ej. app/Config/captcha.php) ni de su cwd.
    */
    private static ?string $sharedConfigFile = null;

    private ?string $requestMethodBackup = null;

    private array $postBackup = [];

    private ?string $configFile = null;

    public static function setUpBeforeClass(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'captcha-empty-config-');
        file_put_contents($file, '<?php return [];');
        self::$sharedConfigFile = $file;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedConfigFile !== null) {
            @unlink(self::$sharedConfigFile);
            putenv('CAPTCHA_CONFIG');
            self::$sharedConfigFile = null;
        }
    }

    protected function setUp(): void
    {
        Captcha::reset();

        putenv('CAPTCHA_CONFIG=' . self::$sharedConfigFile);

        $this->requestMethodBackup = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->postBackup = $_POST;
        unset($_SERVER['REQUEST_METHOD']);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Captcha::reset();

        /*
        *  El config hermético vuelve a mandar en el siguiente test (los tests
        *  que apuntan el env a otro fichero lo sobreescriben a propósito).
        */
        if (self::$sharedConfigFile !== null) {
            putenv('CAPTCHA_CONFIG=' . self::$sharedConfigFile);
        }

        if ($this->requestMethodBackup === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->requestMethodBackup;
        }
        $_POST = $this->postBackup;

        if ($this->configFile !== null) {
            @unlink($this->configFile);
            $this->configFile = null;
        }
    }

    private function request(string $method, array $post): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post;
    }

    private function withConfigFile(string $body): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'captcha-config-');
        file_put_contents($file, $body);
        $this->configFile = $file;
        putenv('CAPTCHA_CONFIG=' . $file);

        return $file;
    }

    public function testInstanceIsLazyAndReused(): void
    {
        $first = Captcha::instance();
        $second = Captcha::instance();

        self::assertSame($first, $second);
        self::assertSame(Config::DEFAULT_LENGTH, $first->config()->length);
    }

    public function testResetDiscardsTheSingleton(): void
    {
        $first = Captcha::instance();
        Captcha::reset();
        $second = Captcha::instance();

        self::assertNotSame($first, $second);
    }

    public function testInstanceLoadsConfigFromEnvironment(): void
    {
        $this->withConfigFile('<?php return [\'length\' => 6, \'ttl\' => 60];');

        $config = Captcha::instance()->config();

        self::assertSame(6, $config->length);
        self::assertSame(60, $config->ttl);
    }

    public function testInstanceRejectsNonArrayConfig(): void
    {
        $this->withConfigFile('<?php return "nope";');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('debe devolver un array');

        Captcha::instance();
    }

    public function testStaticForwardingRejectsUnknownCall(): void
    {
        $this->expectException(\BadMethodCallException::class);

        Captcha::doesNotExist();
    }

    /**
     * Todo método público de la fachada debe responder a la grafía estática.
     *
     * PHP prohíbe un método estático y otro de instancia compartiendo nombre,
     * así que los métodos de flujo viven como implementaciones privadas
     * *Instance detrás del despacho dinámico. generate(), verify(),
     * verifyRequest(), config() y storage() quedaron antes como métodos
     * públicos reales, lo que hacía que PHP rechazara `Captcha::generate()`
     * con un Error fatal ("cannot be called statically") — un crash no
     * capturable por excepción en la página del host, mientras el método
     * hermano assets() sí funcionaba. Ahora todo método público responde a
     * ambas grafías, y uno desconocido es una BadMethodCallException con
     * mensaje en español en lugar de un Error fatal.
     */
    public function testPublicApiAnswersTheStaticSpellingWithoutFatalError(): void
    {
        $result = Captcha::generate();

        self::assertNotSame('', $result->getId());
        self::assertSame('ok', Captcha::verify($result->getId(), self::codeOf($result->getId()))->getStatus()->value);
        self::assertInstanceOf(Config::class, Captcha::config());
        self::assertNotNull(Captcha::storage());
        self::assertStringContainsString('data-captcha', Captcha::widget());
        self::assertNotSame('', Captcha::assets());
    }

    public function testGenerateHasNoSeparateInstanceImplementation(): void
    {
        /*
        *  Guardarraíl: si alguien reintroduce un método público real con
        *  estos nombres, PHP vuelve a rechazar la forma estática y el test
        *  anterior falla; este señala exactamente dónde arreglarlo.
        */
        $reflection = new \ReflectionClass(Captcha::class);

        foreach (['generate', 'verify', 'verifyRequest', 'config', 'storage'] as $name) {
            self::assertFalse(
                $reflection->hasMethod($name),
                sprintf(
                    'Captcha::%s() no puede ser un método público real: PHP rechazaría la forma estática. Debe vivir como %sInstance().',
                    $name,
                    $name,
                ),
            );

            self::assertTrue(
                $reflection->getMethod($name . 'Instance')->isPrivate(),
                sprintf(
                    'Captcha::%sInstance() debe ser privado: la API pública la enruta en ambas grafías.',
                    $name,
                ),
            );
        }
    }

    /**
     * El código detrás de un id de reto, leído a través del storage que la
     * capa estática resolvió de verdad. El auto-storage elige backend según
     * el host (fichero bajo un framework propietario de la sesión, sesión en
     * PHP plano/CLI), así que el test pregunta al storage en lugar de asumir
     * una ruta en disco.
     */
    private static function codeOf(string $id): string
    {
        $storage = Captcha::storage();
        $code = $storage->get($id);

        self::assertNotNull($code, sprintf(
            'El storage resuelto (%s) no tiene la entrada "%s".',
            $storage::class,
            $id,
        ));

        return $code;
    }

    public function testStaticWidgetRendersTheSingleton(): void
    {
        $html = Captcha::widget();

        self::assertStringContainsString('data-captcha', $html);
        self::assertStringContainsString('name="captcha_id"', $html);
    }

    public function testStaticAssetsInlineModeReturnsCssAndJs(): void
    {
        $html = Captcha::assets();

        self::assertStringContainsString('<style>', $html);
        self::assertStringContainsString('<script>', $html);
    }

    public function testStaticAssetsUrlModeEqualsTheInstanceForm(): void
    {
        $static = Captcha::assets('url', 'https://cdn.example.com/captcha');
        $instance = Captcha::instance()->assets('url', 'https://cdn.example.com/captcha');
        $staticUrl = (int) preg_match('/<link rel="stylesheet" href="([^"]+)">/', $static, $a);
        $instanceUrl = (int) preg_match('/<link rel="stylesheet" href="([^"]+)">/', $instance, $b);

        self::assertSame(1, $staticUrl);
        self::assertSame(1, $instanceUrl);
        self::assertSame($a[1], $b[1]);
        self::assertStringContainsString('/captcha/captcha.min.css', $static);
        self::assertStringContainsString('/captcha/captcha.min.js', $static);
    }

    public function testStaticWidgetIsIdempotentWithinTheRequest(): void
    {
        $_SESSION['_captcha'] = [];
        $before = count($_SESSION['_captcha'] ?? []);
        $first = Captcha::widget();
        $second = Captcha::widget();
        $firstId = (int) preg_match('/name="captcha_id" value="([a-f0-9]+)"/', $first, $a);
        $secondId = (int) preg_match('/name="captcha_id" value="([a-f0-9]+)"/', $second, $b);

        self::assertSame(1, $firstId);
        self::assertSame(1, $secondId);
        self::assertSame($a[1], $b[1]);
        self::assertSame($before + 1, count($_SESSION['_captcha']), 'Dos llamadas a widget() deben almacenar un solo reto nuevo');
    }

    public function testStaticSubmittedDetectsTheRequestMethod(): void
    {
        self::assertFalse(Captcha::submitted());

        $this->request('POST', []);

        self::assertTrue(Captcha::submitted());
    }

    public function testStaticValidAndMessageShareTheCachedResult(): void
    {
        preg_match('/name="captcha_id" value="([a-f0-9]+)"/', Captcha::widget(), $matches);
        $id = $matches[1];
        $code = $_SESSION['_captcha'][$id]['code'];

        $this->request('POST', ['captcha_id' => $id, 'captcha' => "  {$code}  "]);

        self::assertTrue(Captcha::valid());
        self::assertSame('Código captcha correcto.', Captcha::message());
        self::assertArrayNotHasKey($id, $_SESSION['_captcha'], 'El reto debe consumirse exactamente una vez');
        self::assertSame(
            Status::Missing,
            Captcha::instance()->verify($id, $code)->getStatus(),
            'Un verify() de bajo nivel tras valid() debe informar del reto ya consumido',
        );
    }

    public function testStaticValidAndMessageReportWrongCodeInSpanish(): void
    {
        preg_match('/name="captcha_id" value="([a-f0-9]+)"/', Captcha::widget(), $matches);
        $id = $matches[1];

        $this->request('POST', ['captcha_id' => $id, 'captcha' => '00000']);

        self::assertFalse(Captcha::valid());
        self::assertSame('Código captcha incorrecto.', Captcha::message());
    }

    public function testStaticCheckIsIdleOnGetRequests(): void
    {
        $check = Captcha::check();

        self::assertFalse($check->submitted);
        self::assertFalse($check->passed);
        self::assertNull($check->error);
    }

    public function testStaticCheckPacksThePassedOutcome(): void
    {
        preg_match('/name="captcha_id" value="([a-f0-9]+)"/', Captcha::widget(), $matches);
        $id = $matches[1];
        $code = $_SESSION['_captcha'][$id]['code'];

        $this->request('POST', ['captcha_id' => $id, 'captcha' => $code]);

        $check = Captcha::check();

        self::assertTrue($check->submitted);
        self::assertTrue($check->passed);
        self::assertNull($check->error);
        self::assertTrue(Captcha::valid(), 'valid() debe reutilizar el resultado que check() dejó en caché');
    }

    public function testStaticCheckReportsTheSpanishErrorOnFailure(): void
    {
        preg_match('/name="captcha_id" value="([a-f0-9]+)"/', Captcha::widget(), $matches);
        $id = $matches[1];

        $this->request('POST', ['captcha_id' => $id, 'captcha' => '00000']);

        $check = Captcha::check();

        self::assertTrue($check->submitted);
        self::assertFalse($check->passed);
        self::assertSame('Código captcha incorrecto.', $check->error);
    }
}
