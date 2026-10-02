<?php

declare(strict_types=1);

namespace Captcha\Console;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Config\ConfigFile;
use Captcha\Contract\StorageInterface;
use Captcha\Runtime\Host;
use Captcha\Runtime\StaticLayer;
use Captcha\Storage\ArrayStorage;
use Captcha\Storage\FileStorage;
use Captcha\Storage\SessionStorage;
use Throwable;

/**
 * Reporte de entorno de solo lectura detrás de `vendor/bin/captcha doctor`.
 *
 * Refleja la instalación de fábrica: requisitos de runtime (PHP, ext-gd),
 * descubrimiento del config (el mismo orden de candidatas que
 * Captcha::bootstrap()), la configuración efectiva con su postura de
 * seguridad (rate limits, honeypot, proxies) y el drop-in del endpoint de
 * recarga. No escribe nada.
 *
 * Devuelve un DoctorReport, no un texto, y esa es la diferencia que le da
 * utilidad fuera de una terminal: cada hallazgo lleva su gravedad, así que
 * el comando puede terminar con un código de salida que un script de
 * despliegue pueda vigilar. Antes devolvía cadenas con la marca ya pegada
 * dentro y no había forma de preguntar por la gravedad sin analyze el texto.
 *
 * La graduación no es decorativa y separa lo que rompe de lo que informa:
 *
 *   - Error    impide que el captcha funcione: falta ext-gd, la versión de
 *              PHP no sirve o el config escrito no arranca la fachada.
 *   - Warning  una configuración que probablemente no es la pretendida: rate
 *              limits a cero, honeypot apagado, o un config anclado sin firma
 *              que el descubrimiento está ignorando en silencio.
 *   - Notice   lo que conviene saber pero no está mal: no hay config y se
 *              usan los defaults, no se declara ningún proxy de confianza,
 *              o se está ejecutando en CLI.
 *
 * Nada se imprime aquí; las líneas las compone DoctorReport a partir del
 * Style que le pase el bin, según el contrato del paquete que prohíbe echo
 * en src/.
 *
 * @internal
 */
final class Doctor
{
    /**
     * Los siguientes pasos que se imprimen al pie del reporte.
     */
    private const FOOTER = 'Siguientes pasos: Captcha::configure([...]) o Captcha::configure(new Config(...)) '
        . '(o un captcha.php descubierto), Captcha::check() en el POST y Captcha::widget() '
        . 'dentro del <form>.';

    /**
     * Ejecuta todas las comprobaciones y devuelve el reporte estructurado.
     */
    public function report(): DoctorReport
    {
        $findings = [
            ...$this->runtimeFindings(),
            ...$this->configFindings(),
            ...$this->configCandidates(),
            ...$this->effectiveFindings(),
            ...$this->hostFindings(),
        ];

        $findings = [...$findings, ...$this->cierreFindings($findings)];

        return new DoctorReport($findings, footer: self::FOOTER);
    }

    /**
     * Los dos hallazgos de cierre, que dependen de todo lo anterior.
     *
     * @param list<Finding> $previos
     *
     * @return list<Finding>
     */
    private function cierreFindings(array $previos): array
    {
        $pista = $this->frameworkHint();
        $cierre = $pista === null ? [] : [$this->finding(Severity::Ok, $pista)];

        return [...$cierre, $this->summaryFinding([...$previos, ...$cierre])];
    }

    /**
     * Lo mínimo sin lo cual el captcha no puede ni dibujar ni responder.
     *
     * @return list<Finding>
     */
    private function runtimeFindings(): array
    {
        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $gd = extension_loaded('gd') ? $this->gdVersion() : null;

        return [$this->phpFinding($phpOk), $this->gdFinding($gd)];
    }

    private function phpFinding(bool $phpOk): Finding
    {
        return $this->finding(
            $phpOk ? Severity::Ok : Severity::Error,
            $phpOk
                ? sprintf('PHP >= 8.2 (%s instalado)', PHP_VERSION)
                : sprintf('PHP %s no cumple el mínimo 8.2 del paquete.', PHP_VERSION),
        );
    }

    private function gdFinding(?string $gd): Finding
    {
        return $this->finding(
            $gd === null ? Severity::Error : Severity::Ok,
            $gd === null
                ? 'ext-gd NO disponible: instala/habilita la extensión GD'
                : sprintf('ext-gd disponible (%s)', $gd),
        );
    }

    /**
     * Qué config ha encontrado el descubrimiento, si alguno.
     *
     * @return list<Finding>
     */
    private function configFindings(): array
    {
        $path = Captcha::configPath();

        if ($path !== null) {
            return [$this->finding(Severity::Ok, sprintf('Config descubierto: %s', $path))];
        }

        return [$this->finding(
            Severity::Notice,
            'Config: ninguno descubierto (env CAPTCHA_CONFIG, cwd app/Config/, config/, etc/ '
            . 'y anclas de raíz firmadas) — defaults, salvo que llames Captcha::configure([...])',
        )];
    }

    /**
     * Transparencia del descubrimiento: cada candidata en el orden exacto en
     * que la capa estática la sondea, con la decisión tomada (cargada / sin
     * firma / ausente). Las entradas ancladas son las que pueden esconder el
     * config de otra app; la línea de aviso nombra el remedio (variable de
     * env o configure()).
     *
     * @return list<Finding>
     */
    private function configCandidates(): array
    {
        $findings = [];

        foreach (StaticLayer::candidatesForDiagnostics() as $candidate) {
            $finding = $this->candidateFinding($candidate);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Traduce una candidatura del descubrimiento en un hallazgo, o null si no
     * hay nada que decir: un fichero que no existe no es un problema, es lo
     * normal cuando el discovery prueba varias anclas.
     *
     * @param array{path: string, loaded: bool, anchored: bool, signed: bool} $candidate
     */
    private function candidateFinding(array $candidate): ?Finding
    {
        if ($candidate['loaded']) {
            return $this->finding(Severity::Ok, sprintf('cargado  %s', $candidate['path']), 1);
        }

        if (!is_file($candidate['path'])) {
            return null;
        }
        if ($candidate['anchored'] && !$candidate['signed']) {
            return $this->anclaSinFirma($candidate['path']);
        }

        return $this->candidaturaSombreada($candidate['path']);
    }

    private function candidaturaSombreada(string $path): Finding
    {
        return $this->finding(
            Severity::Notice,
            sprintf('válido   %s (sombreado por una candidatura anterior)', $path),
            1,
        );
    }

    /**
     * Construye la instancia estática y resume la configuración efectiva.
     *
     * Diagnóstico: cualquier fallo (config roto, sintaxis, GD...) se reporta
     * como hallazgo y además tumba el comando, porque una fachada que no
     * arranca no tiene un estado que reportar.
     *
     * @return list<Finding>
     */
    private function effectiveFindings(): array
    {
        try {
            $instance = Captcha::instance();
            $config = $instance->config();
            $storage = $instance->storage();
        } catch (Throwable $exception) {
            return [$this->arranqueFallido($exception)];
        }

        return [$this->configSummaryFinding($config), $this->storageFinding($config, $storage), ...$this->securityFindings($config)];
    }

    /**
     * Un ancla sin firma se ignora en silencio en producción: aquí solo se
     * enseña el motivo, porque ese silencio no fue decisión de quien lo escribió
     */
    private function anclaSinFirma(string $path): Finding
    {
        return $this->finding(Severity::Warning, sprintf(
            'ignorado %s (ancla sin firma "%s": no es un config del paquete)',
            $path,
            ConfigFile::SIGNATURE,
        ), 1);
    }

    private function arranqueFallido(Throwable $exception): Finding
    {
        return $this->finding(
            Severity::Error,
            'La fachada no arranca con este config: ' . $exception->getMessage(),
        );
    }

    private function configSummaryFinding(Config $config): Finding
    {
        return $this->finding(Severity::Ok, sprintf(
            'Config efectivo: %d dígitos, imagen %dx%d px, TTL %ds, dificultad %s, ruido %s, distorsión %s',
            $config->length,
            $config->width,
            $config->height,
            $config->ttl,
            $config->difficulty->value,
            $config->noise ? 'sí' : 'no',
            $config->distortion ? 'sí' : 'no',
        ));
    }

    private function storageFinding(Config $config, StorageInterface $storage): Finding
    {
        return $this->finding(Severity::Ok, sprintf(
            'Storage efectivo: %s (opción \'storage\' = "%s")',
            $this->storageName($storage),
            $config->storage,
        ));
    }

    /**
     * Postura de seguridad de la configuración efectiva: los diales del
     * limiter que están APAGADOS se reportan como aviso en lugar de pasar en
     * silencio, porque ese es justamente el estado que una errata puede dejar
     * atrás.
     *
     * @return list<Finding>
     */
    private function securityFindings(Config $config): array
    {
        return [
            $this->verifyRateLimitFinding($config),
            $this->generateRateLimitFinding($config),
            $this->honeypotFinding($config),
            $this->ipRateLimitFinding($config),
            $this->trustedProxiesFinding($config),
        ];
    }

    private function verifyRateLimitFinding(Config $config): Finding
    {
        if ($config->verifyAttempts > 0) {
            $texto = sprintf('Rate limit de verificación: %d intentos / %ds', $config->verifyAttempts, $config->rateLimitWindow);

            return $this->finding(Severity::Ok, $texto);
        }

        return $this->finding(Severity::Warning, 'Rate limit de verificación DESACTIVADO (verifyAttempts = 0)');
    }

    private function generateRateLimitFinding(Config $config): Finding
    {
        if ($config->generateAttempts > 0) {
            $texto = sprintf('Rate limit de generación: %d captchas / %ds (HTTP 429)', $config->generateAttempts, $config->rateLimitWindow);

            return $this->finding(Severity::Ok, $texto);
        }

        return $this->finding(Severity::Warning, 'Rate limit de generación DESACTIVADO (generateAttempts = 0)');
    }

    private function honeypotFinding(Config $config): Finding
    {
        if (! $config->honeypot) {
            return $this->finding(Severity::Warning, 'Honeypot desactivado');
        }

        return $this->finding(
            Severity::Ok,
            sprintf('Honeypot activo (campo "%s")', $config->honeypotField),
        );
    }

    private function ipRateLimitFinding(Config $config): Finding
    {
        if (! $config->rateLimitByIp) {
            return $this->finding(
                Severity::Notice,
                'Rate limit por IP: desactivado (solo sesión)',
            );
        }

        return $this->finding(
            Severity::Ok,
            'Rate limit por IP: activo (dual IP + sesión)',
        );
    }

    private function trustedProxiesFinding(Config $config): Finding
    {
        if ($config->trustedProxies === []) {
            return $this->finding(Severity::Notice, 'Proxies de confianza: ninguno (X-Forwarded-For ignorado)');
        }

        return $this->finding(Severity::Notice, self::proxiesDeclarados($config->trustedProxies));
    }

    /**
     * @param list<string> $proxies
     */
    private static function proxiesDeclarados(array $proxies): string
    {
        return sprintf('Proxies de confianza: %d (%s)', count($proxies), implode(', ', $proxies));
    }

    /**
     * Si el runtime actual parece un framework propietario de la sesión PHP
     * (de modo que el storage por defecto evita session_start() según el
     * contrato de Host compartido con la capa estática).
     *
     * @return list<Finding>
     */
    private function hostFindings(): array
    {
        $framework = Host::frameworkSessionManaged();

        return [$this->finding(
            $framework ? Severity::Ok : Severity::Notice,
            $framework
                ? 'Host: framework propietario de la sesión (auto-storage = ficheros, sin session_start())'
                : 'Host: PHP plano / CLI (auto-storage = sesión)',
        )];
    }

    /**
     * Una línea de recuento, para que el final de la salida se lea sin
     * tener que contar marcas a mano.
     *
     * @param list<Finding> $previos
     */
    private function summaryFinding(array $previos): Finding
    {
        $counts = self::countBySeverity($previos);

        return $this->finding(
            self::worstSeverity($previos) ?? Severity::Ok,
            sprintf('Resumen: %s', implode(', ', self::countParts($counts))),
        );
    }

    /**
     * Recuento por severidad, indexado por el valor del enum.
     *
     * @param list<Finding> $previos
     *
     * @return array<string, int>
     */
    private static function countBySeverity(array $previos): array
    {
        $counts = [];

        foreach ($previos as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Recuento en texto, de la severidad más grave a la más leve: "2 errores,
     * 5 avisos" se lee en orden de gravedad.
     *
     * @param array<string, int> $counts
     *
     * @return list<string>
     */
    private static function countParts(array $counts): array
    {
        $partes = [];

        foreach ([Severity::Error, Severity::Warning, Severity::Notice, Severity::Ok] as $severity) {
            $cuantos = $counts[$severity->value] ?? 0;

            if ($cuantos > 0) {
                $partes[] = sprintf('%d %s', $cuantos, $severity->label());
            }
        }

        return $partes;
    }

    /**
     * Severidad más grave presente, o null si no había hallazgos.
     *
     * @param list<Finding> $previos
     */
    private static function worstSeverity(array $previos): ?Severity
    {
        /*
        *  El array se pasa primero a una variable: PHP 8.5 falla al resolver
        *  la llamada estática si el argumento es directamente el resultado de
        *  un array_map() con una flecha tipada con el propio enum. Escrito así
        *  la llamada es correcta y el fallo desaparece.
        */
        $severidades = array_map(
            static fn(Finding $finding): Severity => $finding->severity,
            $previos,
        );

        return Severity::worst($severidades);
    }

    /**
     * Pista de cableado específica del framework cuando hay un kernel
     * conocido cargado, para que quien integra pase de "host detectado" a
     * "la línea exacta". La detección refleja el contrato de Host:
     * class_exists() con autoload desactivado, así que solo cuentan kernels
     * ya cargados por el framework.
     */
    private function frameworkHint(): ?string
    {
        return match (true) {
            class_exists(\CodeIgniter\CodeIgniter::class, false) => 'Host: CodeIgniter 4 — arranca con Captcha::configure(config(\'Captcha\')->options); plantilla: install codeigniter.',
            class_exists(\Illuminate\Foundation\Application::class, false) => 'Host: Laravel — Captcha::configure(config(\'captcha\')) en un service provider; plantilla: install laravel.',
            class_exists(\Symfony\Component\HttpKernel\Kernel::class, false) => 'Host: Symfony — parámetro desde config/packages/captcha.php pasado a Captcha::configure(); plantilla: install symfony.',
            class_exists(\Cake\Core\Application::class, false) => 'Host: CakePHP — Configure::read(\'Captcha\') en bootstrap(); plantilla: install cakephp.',
            class_exists(\yii\BaseYii::class, false) => 'Host: Yii — Yii::$app->params[\'captcha\'] en el bootstrap; plantilla: install yii.',
            default => null,
        };
    }

    private function storageName(StorageInterface $storage): string
    {
        return match (true) {
            $storage instanceof FileStorage => 'archivos (FileStorage)',
            $storage instanceof SessionStorage => 'sesión (SessionStorage)',
            $storage instanceof ArrayStorage => 'memoria (ArrayStorage)',
            default => get_class($storage),
        };
    }

    /**
     * Versión reportada por gd_info(), o null cuando no está disponible.
     */
    private function gdVersion(): ?string
    {
        if (!function_exists('gd_info')) {
            return null;
        }

        $version = gd_info()['GD Version'] ?? null;

        return is_string($version) ? $version : null;
    }

    private function finding(Severity $severity, string $text, int $indent = 0): Finding
    {
        return new Finding($severity, $text, $indent);
    }
}
