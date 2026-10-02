<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use Captcha\Config\ConfigFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vela por que el nombre retirado del paquete no vuelva a colarse.
 *
 * El paquete se renombró y hoy se llama solo captcha. Toda superficie que se
 * filtra en una aplicación anfitriona — el nombre Composer, el prefijo PSR-4,
 * la variable de env, las claves de sesión, los directorios temporales, los
 * nombres de fichero de assets, los selectores CSS/JS y la firma del config —
 * cambió con el renombrado, y uno a medias es invisible hasta que rompe algo
 * en runtime: una firma obsoleta en el config del host hace que el
 * descubrimiento lo ignore en silencio, y una variable de entorno antigua
 * deja muerta la lectura de env. Ninguna de las dos avisa.
 *
 * Por eso las grafías antiguas están prohibidas en todo el material que se
 * distribuye: código, configuración y documentación. No basta con el
 * código, porque el README es la primera puerta que ve quien llega al
 * paquete y es donde un nombre a medias sobrevive más tiempo.
 *
 * Las grafías están escritas una sola vez en todo el repositorio: la
 * constante RETIRED de esta clase, que es la única razón de que el fichero
 * quede fuera de su propio escaneo. Escribir el nombre antiguo en un
 * comentario, en el README o en un mensaje de fallo no le aporta nada a
 * quien lee el código, y basta con que un grep lo encuentre para que el
 * nombre empiece otra vez a circular.
 */
final class NamingTest extends TestCase
{
    /**
     * Cada grafía del nombre retirado, en todas las casings con las que se
     * escribió (namespace, variable de env, clave de sesión, fichero de
     * asset, clase CSS, nombre de keyframes, claves de config CakePHP/Yii).
     *
     * La variante con espacio no es decorativa: era como se escribía el
     * nombre en los <title> de los ejemplos y en prosa de la documentación.
     * Sobrevive a las cinco anteriores porque ninguna comparte subcadena
     * contigua con ellas —sin el espacio, ninguna la encuentra— y porque un
     * nombre de paquete nunca lleva espacio, así que el hueco delata el
     * texto retido de la era anterior al renombrado.
     *
     * @var list<string>
     */
    private const RETIRED = [
        'pure-captcha',
        'PureCaptcha',
        'PURE_CAPTCHA',
        'pure_captcha',
        'pureCaptcha',
        'Pure Captcha',
    ];

    /**
     * Directorios escaneados en busca del nombre retirado, relativos a la
     * raíz del paquete. El output de los fixtures (tests/Visual/output) son
     * PNGs generados, no fuente, así que queda fuera.
     *
     * @var list<string>
     */
    private const SCANNED = ['src', 'tests', 'tools', 'bin'];

    /**
     * Ficheros de la raíz que también se escanean.
     *
     * El código no es la única superficie donde puede colarse un nombre a
     * medias: el README es lo primero que lee quien llega al paquete, y los
     * ficheros de configuración distribuyen selectores, claves y rutas que
     * una app anfitriona copia tal cual. Vigilarlos cuesta una línea y evita
     * que el nombre regrese por la puerta de atrás.
     *
     * Cada entrada se comprueba antes de escanearse: varios de estos
     * ficheros están gitignorados y no existirán en un clon limpio ni en CI.
     *
     * @var list<string>
     */
    private const ROOT_FILES = [
        'composer.json',
        'README.md',
        'LICENSE',
        'CHANGELOG.md',
        'SECURITY.md',
        '.editorconfig',
        '.gitignore',
        '.phpactor.json',
        '.php-cs-fixer.dist.php',
        'phpunit.xml.dist',
        'phpstan.neon.dist',
        '.gitattributes',
    ];

    /**
     * Ficheros de la raíz que quedan fuera pese a estar en la raíz.
     *
     * Ninguno se distribuye: los cuatro están gitignorados y son material
     * personal o generado. Se listan en vez de confiar en que el recorrido
     * no los alcance, porque cualquier refactor futuro volvería a tropezar
     * con ellos, y porque la caché del formateador es un fichero suelto
     * disfrazado de directorio: se parece a un directorio por el nombre y
     * solo el is_file() delata que no lo es.
     *
     * @var list<string>
     */
    private const EXCLUDED_ROOT_FILES = [
        'AGENTS.md',              // instrucciones personales, gitignorado
        'skills-lock.json',       // bloqueo del CLI de skills, gitignorado
        'composer.lock',          // generado por composer install, gitignorado
        '.php-cs-fixer.cache',    // caché del formateador, gitignorado
    ];

    /**
     * Directorios de la raíz fuera del escaneo: la caché de PHPUnit, que se
     * regenera sola y guarda volcados de las pruebas anteriores, donde un
     * mensaje de fallo antiguo puede seguir guardando el nombre retirado.
     *
     * @var list<string>
     */
    private const EXCLUDED_ROOT_DIRS = ['.phpunit.cache'];

    /**
     * @return list<string>
     */
    public static function spellingProvider(): array
    {
        $cases = [];

        foreach (self::RETIRED as $spelling) {
            $cases[$spelling] = [$spelling];
        }

        return $cases;
    }

    /**
     * @param string $spelling
     */
    #[DataProvider('spellingProvider')]
    public function testRetiredNameIsGone(string $spelling): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $file) {
            $contents = file_get_contents($file);

            if ($contents === false || !str_contains($contents, $spelling)) {
                continue;
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (str_contains($line, $spelling)) {
                    $offenders[] = sprintf('%s:%d', self::relative($file), $number + 1);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "Estas líneas usan una grafía retirada del nombre del paquete: '%s'. "
                . 'El paquete se llama captcha a secas, y esas grafías solo deberían '
                . 'existir en la constante RETIRED de NamingTest. Renómbralas o bórralas '
                . '(los contratos externos — env, firma del config, claves de sesión, '
                . 'selectores CSS — se renombraron sin alias, a propósito).',
                $spelling,
            ),
        );
    }

    public function testComposerNameAndBinariesAreCurrent(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertSame('andexer/captcha', $composer['name']);
        self::assertSame(['bin/captcha'], $composer['bin']);
        self::assertSame(['Captcha\\' => 'src/'], $composer['autoload']['psr-4']);
    }

    public function testConfigSignatureIsCurrent(): void
    {
        /*
        *  La firma es el marcador que exige el descubrimiento anclado a la
        *  raíz, así que un valor erróneo aquí descartaría en silencio el
        *  config del propio host.
        */
        self::assertSame('captcha config v2', ConfigFile::SIGNATURE);
    }

    /**
     * El alcance del escaneo es una decisión, no un efecto secundario: si
     * nadie la vigila, cualquier refactor futuro la estrecha en silencio y
     * el test pasa dando una falsa sensación de cobertura.
     *
     * La cobertura se contrasta contra lo que hay realmente en la raíz, no
     * contra la propia lista de declarados: contrastarla contra la lista
     * dejaría pasar el error que más importa, un nombre mal escrito en
     * ROOT_FILES, porque el fichero real seguiría sin vigilarse y la lista
     * seguiría pareciendo cumplida. Contrastarla con el disco lo detecta, y
     * además obliga a decidir —vigilarlo o eximirlo— cada fichero nuevo que
     * aparezca en la raíz.
     */
    public function testScanCoversWhatIsOnDiskAndRespectsItsExclusions(): void
    {
        $root = dirname(__DIR__, 2);
        $scanned = array_map(static fn(string $path): string => self::relative($path), self::sourceFiles());

        $enDisco = [];

        foreach (scandir($root) ?: [] as $entrada) {
            if (!is_file($root . '/' . $entrada)) {
                continue;
            }

            if (in_array($entrada, self::EXCLUDED_ROOT_FILES, true)) {
                continue;
            }

            $enDisco[] = $entrada;
        }

        self::assertSame(
            [],
            array_values(array_diff($enDisco, $scanned)),
            'Hay ficheros en la raíz del paquete que no se escanean. Añádelos a '
            . 'ROOT_FILES si deben vigilarse, o a EXCLUDED_ROOT_FILES si hay una '
            . 'razón para no distribuirlos.',
        );

        foreach (self::EXCLUDED_ROOT_FILES as $file) {
            self::assertNotContains(
                $file,
                $scanned,
                sprintf('%s es material personal o generado y no debe distribuirse, pero ha entrado en el escaneo.', $file),
            );
        }

        foreach (self::EXCLUDED_ROOT_DIRS as $directory) {
            foreach ($scanned as $path) {
                self::assertStringStartsNotWith(
                    $directory . '/',
                    $path,
                    sprintf('El escaneo ha entrado en la caché %s, que se regenera sola y guarda volcados antiguos.', $directory),
                );
            }
        }

        self::assertNotContains('tests/Unit/NamingTest.php', $scanned);
    }

    /**
     * Todo fichero de texto vigilado: los directorios escaneados y los
     * ficheros sueltos de la raíz.
     *
     * Este mismo fichero queda excluido: tiene que escribir el nombre
     * retirado literalmente para poder prohibirlo, así que escanearse a sí
     * mismo fallaría siempre por su propio data provider.
     *
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $self = __FILE__;
        $files = [];

        foreach (self::SCANNED as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $entry */
            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getPathname() !== $self) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        /*
        *  Ficheros sueltos de la raíz. Varios están gitignorados, así que
        *  pueden no existir: se comprueba antes de añadir y un clon limpio
        *  escanea simplemente menos.
        */
        foreach (self::ROOT_FILES as $file) {
            $path = $root . '/' . $file;

            if (is_file($path) && $path !== $self) {
                $files[] = $path;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        $root = dirname(__DIR__, 2) . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
