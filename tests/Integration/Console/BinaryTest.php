<?php

declare(strict_types=1);

namespace Captcha\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El bin es el único trozo del paquete que no se puede probar en proceso: lee
 * argv de verdad, escribe en STDOUT/STDERR de verdad y termina el proceso con
 * un código de salida. Aquí se ejecuta tal cual lo ejecuta quien lo usa.
 */
final class BinaryTest extends TestCase
{
    /**
     * Autoload mínimo de una app anfitriona: registra el prefijo del paquete
     * contra el src del propio repo, que es donde vive el código en desarrollo.
     */
    private const PSR4_AUTOLOAD = <<<'PHP'
        <?php

        spl_autoload_register(static function (string $clase): void {
            $prefijo = 'Captcha\\';

            if (!str_starts_with($clase, $prefijo)) {
                return;
            }

            $ruta = '__SRC__' . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';

            if (is_file($ruta)) {
                require $ruta;
            }
        });
        PHP;

    /**
     * Lo que Composer escribe en vendor/bin cuando no puede dejar un symlink:
     * publica la ruta real del autoload en una global y a continuación incluye
     * el bin de verdad, cuyo __DIR__ se resuelve a través del symlink del
     * paquete.
     */
    private const PROXY_SCRIPT = <<<'PHP'
        <?php

        $GLOBALS['_composer_autoload_path'] = __DIR__ . '/../autoload.php';

        return require __DIR__ . '/../captcha/bin/captcha';
        PHP;

    private string $binary;

    private string $workspace;

    protected function setUp(): void
    {
        $this->binary = dirname(__DIR__, 3) . '/bin/captcha';
        $this->workspace = sys_get_temp_dir() . '/captcha-cli-' . getmypid() . '-' . uniqid();

        self::assertTrue(mkdir($this->workspace, 0o777, true), 'No se pudo crear el espacio de trabajo.');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->workspace);
    }

    public function testWithoutArgumentsItPrintsTheOverviewAndSucceeds(): void
    {
        $resultado = $this->cli([]);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertStringContainsString('consola del paquete', $resultado['output']);
        self::assertStringContainsString('install', $resultado['output']);
        self::assertStringContainsString('doctor', $resultado['output']);
    }

    public function testTheOverviewNeverCarriesEscapeCodesWhenItIsNotATerminal(): void
    {
        /*
        *  El test corre con la salida capturada, que no es una terminal: si
        *  apareciera color aquí, cualquier `captcha ... > informe.txt`
        *  guardaría códigos de escape en el fichero.
        */
        self::assertStringNotContainsString("\033", $this->cli([])['output']);
    }

    public function testVersionPrintsThePackageName(): void
    {
        $resultado = $this->cli(['--version']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertStringContainsString('andexer/captcha', $resultado['output']);
    }

    public function testHelpOfACommandShowsOnlyThatCommand(): void
    {
        $resultado = $this->cli(['help', 'doctor']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertStringContainsString('--strict', $resultado['output']);
        self::assertStringNotContainsString('--framework', $resultado['output']);
    }

    public function testTheHelpFlagOnACommandIsTheSameAsAskingForIt(): void
    {
        self::assertSame(
            $this->cli(['help', 'install'])['output'],
            $this->cli(['install', '--help'])['output'],
        );
    }

    public function testListAndItsAliasPrintTheSameOverview(): void
    {
        self::assertSame($this->cli(['list'])['output'], $this->cli(['ls'])['output']);
    }

    public function testAnUnknownCommandFailsWithTheMessageOnStandardError(): void
    {
        $resultado = $this->cli(['nope']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('Comando desconocido: "nope".', $resultado['error']);
        self::assertStringContainsString('Comandos disponibles: install, doctor, list, help.', $resultado['error']);
        self::assertSame('', $resultado['output']);
    }

    public function testAnAlmostRightCommandIsSuggested(): void
    {
        $resultado = $this->cli(['instal']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('¿Querías decir "install"?', $resultado['error']);
    }

    public function testAnUnknownOptionSuggestsTheClosestOne(): void
    {
        $resultado = $this->cli(['doctor', '--quie']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('¿Querías decir "--quiet"?', $resultado['error']);
    }

    public function testAnOptionOfAnotherCommandIsRejected(): void
    {
        $resultado = $this->cli(['doctor', '--framework=laravel']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('Opción desconocida', $resultado['error']);
    }

    public function testAnUnexpectedArgumentIsRejected(): void
    {
        $resultado = $this->cli(['doctor', 'extra']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('Argumento inesperado', $resultado['error']);
    }

    public function testTheSameFrameworkTwiceWithDifferentValuesIsAContradiction(): void
    {
        $resultado = $this->cli(['install', 'laravel', '--framework=symfony']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('dos veces', $resultado['error']);
        self::assertDirectoryDoesNotExist($this->workspace . '/config');
    }

    public function testTheSameFrameworkTwiceWithTheSameValueIsAccepted(): void
    {
        $resultado = $this->cli(['install', 'laravel', '--framework=laravel']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertFileExists($this->workspace . '/config/captcha.php');
    }

    public function testInstallWritesTheConfigOfTheGivenFramework(): void
    {
        $resultado = $this->cli(['install', 'symfony']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertFileExists($this->workspace . '/config/packages/captcha.php');
        self::assertStringContainsString('Config creado (symfony)', $resultado['output']);
    }

    /**
     * El config que emite el instalador tiene que ser uno que el propio
     * descubrimiento encuentre.
     *
     * Es la mitad de un fallo que daba exactamente este resultado: el
     * instalador de Symfony escribe en config/packages/, que no estaba en la
     * lista de anclas, así que el fichero quedaba ahí y nadie lo leía — la
     * app arrancaba con los defaults y el usuario creyendo haber configurado
     * algo. Se comprueba con el doctor de verdad, no con la lista de rutas.
     */
    public function testTheConfigItEmitsIsTheOneItsOwnDoctorFinds(): void
    {
        self::assertSame(0, $this->cli(['install', 'symfony'])['code']);

        $resultado = $this->cli(['doctor']);

        self::assertStringContainsString(
            'Config descubierto: ' . $this->workspace . '/config/packages/captcha.php',
            $resultado['output'],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function anchorProvider(): iterable
    {
        yield 'plain' => ['plain', 'app/Config/captcha.php'];
        yield 'codeigniter' => ['codeigniter', 'app/Config/captcha.php'];
        yield 'laravel' => ['laravel', 'config/captcha.php'];
        yield 'symfony' => ['symfony', 'config/packages/captcha.php'];
        yield 'cakephp' => ['cakephp', 'config/captcha.php'];
        yield 'yii' => ['yii', 'config/captcha.php'];
        yield 'janssen' => ['janssen', 'app/Config/captcha.php'];
    }

    /**
     * La misma garantía para los siete frameworks: lo que `install` pone en su
     * ruta canónica es alcanzable por el descubrimiento, sin CAPTCHA_CONFIG ni
     * configure() de por medio.
     */
    #[DataProvider('anchorProvider')]
    public function testEveryFrameworkGetsADiscoverableConfig(string $framework, string $relativo): void
    {
        self::assertSame(0, $this->cli(['install', $framework])['code'], $framework);

        $resultado = $this->cli(['doctor']);

        self::assertStringContainsString('Config descubierto: ' . $this->workspace . '/' . $relativo, $resultado['output'], $framework);
    }

    /** `--dry-run` enseña el plan completo y no deja ni un fichero. */
    public function testDryRunShowsEveryDestinationWithoutTouchingTheDisk(): void
    {
        $resultado = $this->cli(['install', '--dry-run', 'laravel']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertStringContainsString('Simulación', $resultado['output']);
        self::assertStringContainsString('Se crearía (laravel)', $resultado['output']);
        self::assertStringContainsString('Pendientes de registrar a mano', $resultado['output'], 'el plan incluye los pasos a mano');
        self::assertSame(4, substr_count($resultado['output'], 'Se crearía'), 'el config y los tres ficheros de pegamento');
        self::assertDirectoryDoesNotExist($this->workspace . '/config');
        self::assertDirectoryDoesNotExist($this->workspace . '/app');
    }

    /** `-n` es la forma corta, igual que en Composer. */
    public function testTheShortFormOfDryRunIsAccepted(): void
    {
        $resultado = $this->cli(['install', '-n', 'plain']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertDirectoryDoesNotExist($this->workspace . '/public');
    }

    /**
     * Un enlace simbólico en el destino no se sigue.
     *
     * is_file() lo atraviesa, así que un enlace colgado pasaba por "no existe"
     * y el instalador acababa creando el fichero fuera del proyecto, que es
     * escribir fuera de la raíz que el comando resolvió.
     */
    public function testItRefusesToWriteThroughASymbolicLink(): void
    {
        $fuera = $this->workspace . '/fuera';
        $colgado = $this->workspace . '/app/Config/captcha.php';
        mkdir($this->workspace . '/app/Config', 0o777, true);
        mkdir($fuera, 0o777, true);
        symlink($fuera . '/inyectado.php', $colgado);

        $resultado = $this->cli(['install', 'plain']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('enlace simbólico', $resultado['error']);
        self::assertFileDoesNotExist($fuera . '/inyectado.php', 'no debe escribir fuera del proyecto');
    }

    /**
     * La raíz es la del proyecto, no la del directorio desde el que se lanza.
     *
     * Sin esto, un `cd src` antes del comando — o un script de CI que cambia
     * de directorio — deja el config y el pegamento en el subdirectorio, que
     * es el fallo más difícil de ver: la aplicación sigue arrancando.
     */
    public function testItWritesAtTheProjectRootAndNotAtTheWorkingDirectory(): void
    {
        file_put_contents($this->workspace . '/composer.json', '{"name":"host/app"}');
        $interior = $this->workspace . '/src/vendor';
        mkdir($interior, 0o777, true);

        $resultado = $this->cli(['install', 'plain'], null, $interior);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertFileExists($this->workspace . '/app/Config/captcha.php');
        self::assertDirectoryDoesNotExist($interior . '/app', 'no debe escribir en el subdirectorio');
    }

    /**
     * Los ficheros que escribe no los puede tocar nadie más.
     *
     * fopen() crea con 0666 menos el umask, así que el umask se pone a cero
     * aquí a propósito: es lo que separa un chmod de 0644 de un config
     * escribible por cualquier usuario del sistema, y ese config es un PHP que
     * la aplicación incluye.
     */
    public function testTheFilesItWritesAreNotWritableByAnyoneElse(): void
    {
        $anterior = umask(0o000);

        try {
            self::assertSame(0, $this->cli(['install', 'plain'])['code']);
        } finally {
            umask($anterior);
        }

        foreach (['app/Config/captcha.php', 'src/CaptchaGuard.php'] as $relativo) {
            $ruta = $this->workspace . '/' . $relativo;
            $modo = fileperms($ruta);

            self::assertIsInt($modo, $relativo);
            self::assertSame(0o644, $modo & 0o777, $relativo . ' no debe ser escribible por el grupo ni por otros');
        }

        self::assertSame(0o755, fileperms($this->workspace . '/app/Config') & 0o777);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function integrationProvider(): iterable
    {
        yield 'plain' => ['plain', ['src/CaptchaGuard.php', 'public/captcha.php']];
        yield 'codeigniter' => ['codeigniter', ['app/Filters/CaptchaFilter.php', 'app/Controllers/Captcha.php']];
        yield 'laravel' => ['laravel', [
            'app/Providers/CaptchaServiceProvider.php',
            'app/Http/Middleware/CaptchaGuardMiddleware.php',
            'app/Http/Controllers/CaptchaController.php',
        ]];
        yield 'cakephp' => ['cakephp', [
            'src/Middleware/CaptchaMiddleware.php',
            'src/Controller/CaptchaController.php',
        ]];
        yield 'yii' => ['yii', [
            'src/filters/CaptchaFilter.php',
            'src/controllers/CaptchaController.php',
        ]];
        yield 'janssen' => ['janssen', [
            'app/Preprocessor/CaptchaGuard.php',
            'app/Controller/CaptchaController.php',
            'templates/captcha-test.php',
        ]];
    }

    #[DataProvider('integrationProvider')]
    public function testInstallAlsoWritesTheIntegrationOfTheFramework(string $framework, array $esperados): void
    {
        $resultado = $this->cli(['install', $framework]);

        self::assertSame(0, $resultado['code'], $resultado['error']);

        foreach ($esperados as $relativo) {
            self::assertFileExists($this->workspace . '/' . $relativo, $framework . ': falta ' . $relativo);
        }

        self::assertStringContainsString('Integración creada', $resultado['output'], $framework);
        self::assertStringContainsString('Pendientes de registrar a mano', $resultado['output'], $framework);
    }

    #[DataProvider('integrationProvider')]
    public function testTheIntegrationItWritesIsSyntacticallyValidPhp(string $framework, array $esperados): void
    {
        $this->cli(['install', $framework]);

        foreach ($esperados as $relativo) {
            $ruta = $this->workspace . '/' . $relativo;

            exec('php -l ' . escapeshellarg($ruta), $salida, $codigo);

            self::assertSame(0, $codigo, $relativo . ': ' . implode(' ', $salida));
        }
    }

    public function testInstallPrintsTheRegistrationStepOfEveryWiredFile(): void
    {
        $resultado = $this->cli(['install', 'laravel']);

        self::assertStringContainsString('CaptchaServiceProvider::class', $resultado['output']);
        self::assertStringContainsString('$middleware->append', $resultado['output']);
        self::assertStringContainsString('Route::get', $resultado['output']);
    }

    public function testInstallingTwiceLeavesTheIntegrationUntouched(): void
    {
        $this->cli(['install', 'laravel']);
        $antes = $this->workspace . '/app/Http/Middleware/CaptchaGuardMiddleware.php';
        $contenido = (string) file_get_contents($antes);

        $resultado = $this->cli(['install', 'laravel']);

        self::assertSame($contenido, (string) file_get_contents($antes), 'el middleware no debe tocarse');
        self::assertStringContainsString('Ya existe, no se toca', $resultado['output']);
    }

    public function testInstallNeverOverwritesAnExistingConfig(): void
    {
        $this->cli(['install', 'laravel']);
        $ruta = $this->workspace . '/config/captcha.php';
        file_put_contents($ruta, "// escrito a mano\n");

        $resultado = $this->cli(['install', 'laravel']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertStringContainsString('Ya existe, no se toca', $resultado['output']);
        self::assertSame("// escrito a mano\n", file_get_contents($ruta));
    }

    public function testInstallRejectsAnUnknownFramework(): void
    {
        $resultado = $this->cli(['install', 'drupal']);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('no reconocido', $resultado['error']);
    }

    public function testQuietInstallSaysNothingOnSuccess(): void
    {
        $resultado = $this->cli(['install', 'laravel', '--quiet']);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertSame('', $resultado['output']);
        self::assertFileExists($this->workspace . '/config/captcha.php');
    }

    public function testDoctorReportsTheRuntimeAndSucceedsWithoutErrors(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Requiere ext-gd.');
        }

        $resultado = $this->cli(['doctor']);

        self::assertStringContainsString('ext-gd', $resultado['output']);
        self::assertStringNotContainsString('[XX]', $resultado['output']);
        self::assertSame(0, $resultado['code'], $resultado['output']);
    }

    /**
     * La relación entre los dos códigos es la promises de `--strict`: solo
     * cambia cuando hay avisos, y nunca salva un error.
     */
    public function testStrictOnlyChangesTheExitCodeWhenThereAreWarnings(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Requiere ext-gd.');
        }

        $normal = $this->cli(['doctor']);
        $estricto = $this->cli(['doctor', '--strict']);

        self::assertSame(0, $normal['code'], $normal['output']);
        self::assertSame(
            str_contains($normal['output'], '[!!]') ? 1 : 0,
            $estricto['code'],
        );
    }

    public function testQuietDoctorOnlyLeavesTheErrors(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('Requiere ext-gd.');
        }

        $completo = $this->cli(['doctor'])['output'];
        $silencioso = $this->cli(['doctor', '--quiet'])['output'];

        self::assertStringContainsString('ext-gd', $completo);
        self::assertStringNotContainsString('[ok]', $silencioso);
        self::assertStringNotContainsString('[..]', $silencioso);
    }

    public function testTheDoctorAliasBehavesLikeTheCommand(): void
    {
        $resultado = $this->cli(['d', '--strict']);

        self::assertSame($this->cli(['doctor', '--strict'])['output'], $resultado['output']);
    }

    /**
     * Composer deja un symlink en vendor/bin salvo que no pueda (Windows,
     * bin-compat: full, un repositorio path sin symlink). Cuando no puede
     * escribe un proxy que publica la ruta real del autoload en una global, y
     * esa global es el único sitio desde donde se puede llegar al autoload: las
     * dos rutas relativas se resuelven desde el bin real, a través del symlink
     * del paquete, y apuntan fuera del proyecto.
     *
     * Es el caso de quien desarrolla contra el paquete con un repositorio
     * path, así que se reproduce entero: paquete symlinkeado, sin vendor
     * propio, y el proxy de Composer delante.
     */
    public function testTheProxyGeneratedBinFindsTheAutoloadThroughTheComposerGlobal(): void
    {
        $proxy = $this->buildProxyLayout();

        $resultado = $this->cli(['--version'], $proxy);

        self::assertSame(0, $resultado['code'], $resultado['error']);
        self::assertStringContainsString('andexer/captcha', $resultado['output']);
    }

    /**
     * El mismo proxy, pero apuntando a un autoload que no existe: el shim debe
     * seguir las tres rutas y explicar el fallo, no reventar con un aviso de
     * require.
     */
    public function testTheProxyGeneratedBinExplainsItselfWhenTheAutoloadIsMissing(): void
    {
        $proxy = $this->buildProxyLayout();
        self::assertTrue(unlink(dirname($proxy) . '/../autoload.php'), 'No se pudo borrar el autoload.');

        $resultado = $this->cli(['--version'], $proxy);

        self::assertSame(1, $resultado['code']);
        self::assertStringContainsString('No se encontró el autoload', $resultado['error']);
    }

    /**
     * Monta el layout que genera Composer con un repositorio path y devuelve
     * la ruta del proxy de vendor/bin.
     */
    private function buildProxyLayout(): string
    {
        $raiz = $this->workspace . '/proxy-' . uniqid();
        $paquete = $raiz . '/pkg';

        self::assertTrue(mkdir($paquete . '/bin', 0o777, true), 'No se pudo crear el paquete.');
        self::assertTrue(mkdir($raiz . '/vendor/bin', 0o777, true), 'No se pudo crear vendor/bin.');

        $binario = dirname(__DIR__, 3) . '/bin/captcha';
        self::assertTrue(copy($binario, $paquete . '/bin/captcha'), 'No se pudo copiar el bin.');
        self::assertTrue(
            symlink($paquete, $raiz . '/vendor/captcha'),
            'No se pudo enlazar el paquete, como haría un repositorio path.',
        );

        /*
        *  El autoload que ve la app anfitriona, con el PSR-4 del paquete detrás.
        *  Se escribe con el src del repo real para no duplicar el árbol: lo que
        *  importa aquí es que la ruta exista y registre el prefijo.
        */
        file_put_contents(
            $raiz . '/vendor/autoload.php',
            str_replace('__SRC__', dirname(__DIR__, 3) . '/src', self::PSR4_AUTOLOAD),
        );

        // El proxy de Composer: publica el autoload y después incluye el bin real.
        $proxy = $raiz . '/vendor/bin/captcha';
        file_put_contents($proxy, self::PROXY_SCRIPT);

        return $proxy;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{output: string, error: string, code: int}
     */
    private function cli(array $arguments, ?string $script = null, ?string $cwd = null): array
    {
        $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script ?? $this->binary);

        foreach ($arguments as $argument) {
            $comando .= ' ' . escapeshellarg($argument);
        }

        $descriptores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proceso = proc_open($comando, $descriptores, $canales, $cwd ?? $this->workspace);

        self::assertIsResource($proceso, 'No se pudo lanzar el bin.');

        $salida = (string) stream_get_contents($canales[1]);
        $error = (string) stream_get_contents($canales[2]);

        fclose($canales[1]);
        fclose($canales[2]);

        return ['output' => $salida, 'error' => $error, 'code' => proc_close($proceso)];
    }

    private static function removeTree(string $path): void
    {
        /*
        *  Un symlink a un directorio cuenta como is_dir(), así que sin esta
        *  primera comprobación el iterador entraría en el destino y el rmdir
        *  final fallaría con "Directory not empty". El enlace se borra él solo.
        */
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entradas = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entradas as $entrada) {
            if ($entrada->isLink()) {
                unlink($entrada->getPathname());

                continue;
            }

            $entrada->isDir() ? rmdir($entrada->getPathname()) : unlink($entrada->getPathname());
        }

        rmdir($path);
    }
}
