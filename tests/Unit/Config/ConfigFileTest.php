<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\ConfigFile;
use PHPUnit\Framework\TestCase;

/**
 * La puerta de firma para el descubrimiento de config anclado a la raíz
 * (#1): solo se aceptan ficheros que llevan el comentario // captcha config
 * v2, y solo cuando el token está en una línea de comentario // (un literal
 * de cadena no debe poder falsificarlo).
 */
final class ConfigFileTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null) {
            @unlink($this->file);
            $this->file = null;
        }
    }

    public function testMissingFileHasNoSignature(): void
    {
        self::assertFalse(ConfigFile::carriesSignature('/captcha/does-not-exist.php'));
    }

    public function testFileWithoutSignatureIsRejected(): void
    {
        $path = $this->write('<?php return [];');

        self::assertFalse(ConfigFile::carriesSignature($path));
    }

    public function testSignedFileIsAccepted(): void
    {
        $path = $this->write("<?php\n// captcha config v2\nreturn [];");

        self::assertTrue(ConfigFile::carriesSignature($path));
    }

    public function testSignatureIsAcceptedInsideACommentBlock(): void
    {
        $path = $this->write("<?php\n// Configuración de captcha\n// captcha config v2\n// más texto\nreturn [];");

        self::assertTrue(ConfigFile::carriesSignature($path));
    }

    public function testStringLiteralCannotForgeTheSignature(): void
    {
        $path = $this->write("<?php return ['note' => 'captcha config v2'];");

        self::assertFalse(ConfigFile::carriesSignature($path));
    }

    public function testSignatureBeyondTheHeadIsNotScanned(): void
    {
        /*
        *  El token solo cuenta en la cabecera del fichero: un config puede
        *  citar la firma dentro de un comentario profundo sin ser firmado.
        */
        $deep = "<?php\n// padding\n" . str_repeat('// línea de relleno para empujar el token más allá de la ventana de cabecera' . "\n", 130) . "\n// captcha config v2\nreturn [];";
        $path = $this->write($deep);

        self::assertFalse(ConfigFile::carriesSignature($path));
    }

    public function testInstallTemplatesAllCarryTheSignature(): void
    {
        foreach (['plain', 'codeigniter', 'laravel', 'symfony', 'cakephp', 'yii', 'janssen'] as $framework) {
            $path = dirname(__DIR__, 3) . '/src/app/Config/templates/' . $framework . '.php';

            self::assertFileExists($path);
            self::assertTrue(
                ConfigFile::carriesSignature($path),
                "La plantilla {$framework}.php debe llevar la firma del paquete.",
            );
        }
    }

    private function write(string $body): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'captcha-configfile-');
        file_put_contents($file, $body);
        $this->file = $file;

        return $file;
    }
}
