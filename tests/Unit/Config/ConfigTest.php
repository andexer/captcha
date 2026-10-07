<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Config\Operation;
use Captcha\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsAreValid(): void
    {
        $config = new Config();

        self::assertSame(Config::DEFAULT_LENGTH, $config->length);
        self::assertSame(Config::DEFAULT_WIDTH, $config->width);
        self::assertSame(Config::DEFAULT_HEIGHT, $config->height);
        self::assertSame(Config::DEFAULT_TTL, $config->ttl);
        self::assertSame(Difficulty::Medium, $config->difficulty);
        self::assertTrue($config->noise);
        self::assertTrue($config->distortion);
        self::assertSame('png', $config->output);
        self::assertSame('captcha_id', $config->idField);
        self::assertSame('captcha', $config->inputField);
        self::assertTrue($config->injectAssets);
    }

    public function testCustomValuesAreKept(): void
    {
        $config = new Config(
            length: 8,
            width: 300,
            height: 100,
            ttl: 60,
            difficulty: Difficulty::High,
            noise: false,
            distortion: false,
        );

        self::assertSame(8, $config->length);
        self::assertSame(300, $config->width);
        self::assertSame(100, $config->height);
        self::assertSame(60, $config->ttl);
        self::assertSame(Difficulty::High, $config->difficulty);
        self::assertFalse($config->noise);
        self::assertFalse($config->distortion);
    }

    /**
     * Asimetría deliberada entre las dos rutas de construcción: el constructor
     * exige los tipos PHP nativos, así que un valor de otro tipo es un
     * TypeError de PHP y no una excepción del paquete, mientras que la misma
     * entrada por array (ver testFromArrayRejectsNonBoolNoise) es
     * InvalidConfigException con mensaje en español. Ahí no hay tipos que
     * delataran el fallo antes, porque el array no los trae. El rango malo
     * sí es InvalidConfigException en las dos vías.
     */
    public function testNativeTypeMismatchIsATypeErrorOnTheClassPath(): void
    {
        $this->expectException(\TypeError::class);

        new Config(noise: 'yes');
    }

    public function testRejectsLengthBelowMinimum(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('La longitud debe estar entre 3 y 10');

        new Config(length: 2);
    }

    public function testRejectsLengthAboveMaximum(): void
    {
        $this->expectException(InvalidConfigException::class);

        new Config(length: 11);
    }

    public function testRejectsNonPositiveWidth(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('El ancho debe estar entre 1 y 5000');

        new Config(width: 0);
    }

    public function testRejectsNonPositiveHeight(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('El alto debe estar entre 1 y 2000');

        new Config(height: -1);
    }

    public function testRejectsOversizedWidth(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('El ancho debe estar entre 1 y 5000');

        new Config(width: Config::MAX_WIDTH + 1);
    }

    public function testRejectsOversizedHeight(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('El alto debe estar entre 1 y 2000');

        new Config(height: Config::MAX_HEIGHT + 1);
    }

    public function testAcceptsUpperBounds(): void
    {
        $config = new Config(width: Config::MAX_WIDTH, height: Config::MAX_HEIGHT);

        self::assertSame(Config::MAX_WIDTH, $config->width);
        self::assertSame(Config::MAX_HEIGHT, $config->height);
    }

    public function testRejectsNonPositiveTtl(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('La TTL debe ser de al menos 1 segundo');

        new Config(ttl: 0);
    }

    public function testRejectsUnsupportedOutput(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Formato de salida "gif" no soportado');

        new Config(output: 'gif');
    }

    public function testRejectsEmptyIdField(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Los nombres de los campos del formulario no pueden estar vacíos');

        new Config(idField: '');
    }

    public function testRejectsEmptyInputField(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Los nombres de los campos del formulario no pueden estar vacíos');

        new Config(inputField: '');
    }

    public function testCustomFieldNamesAndAssetInjectionAreKept(): void
    {
        $config = new Config(idField: 'challenge_id', inputField: 'code', injectAssets: false);

        self::assertSame('challenge_id', $config->idField);
        self::assertSame('code', $config->inputField);
        self::assertFalse($config->injectAssets);
    }

    /**
     * De fábrica el paquete debe estar limitado por tasa: una instalación
     * sin configurar tenía ambos presupuestos a 0, lo que dejaba el flujo de
     * verificación expuesto a fuerza bruta (un código aritmético de 6 dígitos
     * tenía un espacio de respuestas de ~197 valores y sin techo de
     * intentos). Desactivar sigue siendo posible con un 0 explícito, nunca
     * por omisión.
     */
    public function testRateLimitDefaultsAreEnabled(): void
    {
        $config = new Config();

        self::assertSame(5, $config->verifyAttempts);
        self::assertSame(20, $config->generateAttempts);
        self::assertSame(300, $config->rateLimitWindow);
    }

    public function testRateLimitAndHoneypotValuesAreKept(): void
    {
        $config = new Config(
            verifyAttempts: 5,
            generateAttempts: 10,
            rateLimitWindow: 120,
            honeypot: true,
            honeypotField: 'website',
        );

        self::assertSame(5, $config->verifyAttempts);
        self::assertSame(10, $config->generateAttempts);
        self::assertSame(120, $config->rateLimitWindow);
        self::assertTrue($config->honeypot);
        self::assertSame('website', $config->honeypotField);
    }

    public function testRejectsNegativeVerifyAttempts(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('El límite de intentos de verificación no puede ser negativo');

        new Config(verifyAttempts: -1);
    }

    public function testRejectsNegativeGenerateAttempts(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('El límite de generación no puede ser negativo');

        new Config(generateAttempts: -1);
    }

    public function testRejectsZeroRateLimitWindow(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('La ventana del rate limit debe ser de al menos 1 segundo');

        new Config(rateLimitWindow: 0);
    }

    public function testRejectsEmptyHoneypotFieldWhenEnabled(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('El nombre del campo honeypot no puede estar vacío');

        new Config(honeypot: true, honeypotField: '');
    }

    public function testRejectsHoneypotFieldCollidingWithInputField(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("El campo honeypot 'captcha' no puede coincidir con un campo real del captcha");

        new Config(honeypot: true, honeypotField: 'captcha');
    }

    public function testRejectsHoneypotFieldCollidingWithIdField(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("El campo honeypot 'captcha_id' no puede coincidir con un campo real del captcha");

        new Config(honeypot: true, honeypotField: 'captcha_id');
    }

    public function testAllowsHoneypotFieldDistinctFromTheRealFields(): void
    {
        $config = new Config(honeypot: true, honeypotField: 'website');

        self::assertTrue($config->honeypot);
        self::assertSame('website', $config->honeypotField);
    }

    public function testFromArrayEmptyUsesDefaults(): void
    {
        $config = Config::fromArray([]);

        self::assertSame(Config::DEFAULT_LENGTH, $config->length);
        self::assertSame(Config::DEFAULT_TTL, $config->ttl);
        self::assertTrue($config->noise);
        self::assertSame(Difficulty::Medium, $config->difficulty);
    }

    public function testFromArrayKeepsProvidedValues(): void
    {
        $config = Config::fromArray(['length' => 6, 'ttl' => 60]);

        self::assertSame(6, $config->length);
        self::assertSame(60, $config->ttl);
        self::assertSame(Config::DEFAULT_WIDTH, $config->width);
    }

    public function testFromArrayAcceptsNumericStringLength(): void
    {
        $config = Config::fromArray(['length' => '6']);

        self::assertSame(6, $config->length);
    }

    public function testFromArrayRejectsNonNumericLengthString(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'length' debe ser un entero");

        Config::fromArray(['length' => 'abc']);
    }

    public function testFromArrayRejectsNonScalarNumericLength(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'length' debe ser un entero");

        Config::fromArray(['length' => 3.5]);
    }

    public function testFromArrayAcceptsDifficultyString(): void
    {
        $config = Config::fromArray(['difficulty' => 'high']);

        self::assertSame(Difficulty::High, $config->difficulty);
    }

    public function testFromArrayAcceptsDifficultyEnum(): void
    {
        $config = Config::fromArray(['difficulty' => Difficulty::Low]);

        self::assertSame(Difficulty::Low, $config->difficulty);
    }

    public function testFromArrayRejectsUnknownDifficulty(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'difficulty' no es válido");

        Config::fromArray(['difficulty' => 'huge']);
    }

    public function testFromArrayRejectsEnumIntDifficulty(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'difficulty' debe ser un texto");

        Config::fromArray(['difficulty' => 1]);
    }

    public function testFromArrayRejectsUnknownKeysByDefault(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Claves de configuración no reconocidas: "unknown", "framework".');

        Config::fromArray(['unknown' => 'x', 'framework' => 'janssen']);
    }

    public function testFromArrayLenientModeIgnoresUnknownKeys(): void
    {
        $config = Config::fromArray(['unknown' => 'x', 'framework' => 'janssen'], strict: false);

        self::assertSame(Config::DEFAULT_LENGTH, $config->length);
    }

    public function testFromArrayStrictModeAcceptsThePresetShortcut(): void
    {
        $config = Config::fromArray(['preset' => 'login', 'length' => 6]);

        self::assertSame(6, $config->length);
    }

    public function testFromArrayRejectsNonBoolNoise(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'noise' debe ser booleano");

        Config::fromArray(['noise' => 1]);
    }

    public function testFromArrayRejectsNonStringOutput(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'output' debe ser texto");

        Config::fromArray(['output' => true]);
    }

    public function testFromArrayAcceptsOperationsBooleanMap(): void
    {
        $config = Config::fromArray([
            'operations' => ['addition' => true, 'division' => true, 'multiplication' => false],
        ]);

        self::assertSame([Operation::Add, Operation::Divide], $config->operations);
    }

    public function testFromArrayAcceptsShortOperationAliases(): void
    {
        $config = Config::fromArray([
            'operations' => ['add' => true, 'subtract' => true],
        ]);

        self::assertSame([Operation::Add, Operation::Subtract], $config->operations);
    }

    public function testFromArrayAcceptsSymbolListOperations(): void
    {
        $config = Config::fromArray(['operations' => ['+', '-']]);

        self::assertSame([Operation::Add, Operation::Subtract], $config->operations);
    }

    public function testFromArrayAcceptsNameListOperations(): void
    {
        $config = Config::fromArray(['operations' => ['addition', 'subtraction']]);

        self::assertSame([Operation::Add, Operation::Subtract], $config->operations);
    }

    public function testFromArrayNormalizesAllOperationShapesIdentically(): void
    {
        $symbols = Config::fromArray(['operations' => ['+', '-']]);
        $names = Config::fromArray(['operations' => ['addition', 'subtraction']]);
        $abbreviations = Config::fromArray(['operations' => ['add', 'sub']]);
        $map = Config::fromArray(['operations' => ['addition' => true, 'subtraction' => true]]);
        $cases = Config::fromArray(['operations' => [Operation::Add, Operation::Subtract]]);

        self::assertSame($symbols->operations, $names->operations);
        self::assertSame($names->operations, $abbreviations->operations);
        self::assertSame($abbreviations->operations, $map->operations);
        self::assertSame($map->operations, $cases->operations);
    }

    public function testFromArrayAcceptsUnicodeAndAbbreviatedAliases(): void
    {
        $config = Config::fromArray(['operations' => ['×', '÷', 'sum', 'subs']]);

        self::assertSame(
            [Operation::Multiply, Operation::Divide, Operation::Add, Operation::Subtract],
            $config->operations,
        );
    }

    public function testFromArrayDedupesListOperations(): void
    {
        $config = Config::fromArray(['operations' => ['+', 'add', 'addition']]);

        self::assertSame([Operation::Add], $config->operations);
    }

    public function testFromArrayAcceptsEmptyListOperationsAsDigits(): void
    {
        $config = Config::fromArray(['operations' => []]);

        self::assertSame([], $config->operations);
    }

    public function testFromArrayRejectsUnknownListOperation(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Operación no reconocida: "adittion"');

        Config::fromArray(['operations' => ['addition', 'adittion']]);
    }

    public function testFromArrayRejectsNonScalarListOperation(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Operación no reconocida');

        Config::fromArray(['operations' => [['add']]]);
    }

    public function testFromArrayIgnoresUnknownOperationKeys(): void
    {
        $config = Config::fromArray([
            'operations' => ['factorial' => true, 'modulo' => true],
        ]);

        self::assertSame([], $config->operations);
    }

    public function testFromArrayAllFalseOperationsMeansDigits(): void
    {
        $config = Config::fromArray([
            'operations' => ['addition' => false, 'subtraction' => false],
        ]);

        self::assertSame([], $config->operations);
    }

    public function testFromArrayRejectsNonBoolOperationFlag(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'addition' debe ser booleano");

        Config::fromArray(['operations' => ['addition' => 'yes']]);
    }

    public function testFromArrayRejectsNonArrayOperations(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'operations' debe ser un array");

        Config::fromArray(['operations' => true]);
    }

    public function testFromArrayOmitsOperationsToEmptyList(): void
    {
        $config = Config::fromArray([]);

        self::assertSame([], $config->operations);
    }

    public function testFromArrayAcceptsBetweenRange(): void
    {
        $config = Config::fromArray([
            'operations' => ['+', '-'],
            'between' => [2, 20],
        ]);

        self::assertSame([2, 20], $config->between);
    }

    public function testFromArrayAcceptsNumericStringBetween(): void
    {
        $config = Config::fromArray([
            'operations' => ['+'],
            'between' => ['2', '20'],
        ]);

        self::assertSame([2, 20], $config->between);
    }

    public function testConstructorKeepsBetweenWhenArithmetic(): void
    {
        $config = new Config(operations: [Operation::Add], between: [2, 20]);

        self::assertSame([2, 20], $config->between);
    }

    public function testFromArrayRejectsNonArrayBetween(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'between' debe ser un array de dos enteros");

        Config::fromArray(['between' => 20]);
    }

    public function testFromArrayRejectsBetweenWrongCount(): void
    {
        $this->expectException(InvalidConfigException::class);

        Config::fromArray(['operations' => ['+'], 'between' => [2, 20, 30]]);
    }

    public function testFromArrayRejectsBetweenNonNumericBound(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'between' debe contener enteros");

        Config::fromArray(['operations' => ['+'], 'between' => ['dos', 20]]);
    }

    public function testConstructorRejectsInvertedBetween(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('no puede ser mayor que el máximo');

        new Config(operations: [Operation::Add], between: [20, 2]);
    }

    public function testConstructorRejectsNegativeBetweenBound(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('no puede ser negativo');

        new Config(operations: [Operation::Add], between: [-5, 20]);
    }

    public function testBetweenRequiresArithmeticMode(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'between' solo tiene efecto en modo aritmético");

        new Config(between: [2, 20]);
    }

    public function testBetweenRequiresArithmeticModeFromArray(): void
    {
        $this->expectException(InvalidConfigException::class);

        Config::fromArray(['between' => [2, 20]]);
    }

    public function testFromArrayAppliesRemainingScalarKeys(): void
    {
        $config = Config::fromArray([
            'width' => 320,
            'height' => 80,
            'distortion' => false,
            'idField' => 'challenge_id',
            'inputField' => 'code',
            'injectAssets' => false,
        ]);

        self::assertSame(320, $config->width);
        self::assertSame(80, $config->height);
        self::assertFalse($config->distortion);
        self::assertSame('challenge_id', $config->idField);
        self::assertSame('code', $config->inputField);
        self::assertFalse($config->injectAssets);
    }

    public function testFromArrayAcceptsRateLimitAndHoneypotKeys(): void
    {
        $config = Config::fromArray([
            'verifyAttempts' => '5',
            'generateAttempts' => 3,
            'rateLimitWindow' => 60,
            'honeypot' => true,
            'honeypotField' => 'website',
        ]);

        self::assertSame(5, $config->verifyAttempts);
        self::assertSame(3, $config->generateAttempts);
        self::assertSame(60, $config->rateLimitWindow);
        self::assertTrue($config->honeypot);
        self::assertSame('website', $config->honeypotField);
    }

    public function testFromArrayRejectsNonBoolHoneypot(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'honeypot' debe ser booleano");

        Config::fromArray(['honeypot' => 1]);
    }

    public function testFromArrayRejectsNonStringHoneypotField(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'honeypotField' debe ser texto");

        Config::fromArray(['honeypotField' => false]);
    }

    public function testRateLimitByIpDefaultsToTrue(): void
    {
        self::assertTrue((new Config())->rateLimitByIp);
        self::assertSame([], (new Config())->trustedProxies);
    }

    public function testFromArrayAcceptsIpLimitingKeys(): void
    {
        $config = Config::fromArray([
            'rateLimitByIp' => false,
            'trustedProxies' => ['10.0.0.1', '10.0.0.2'],
        ]);

        self::assertFalse($config->rateLimitByIp);
        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
    }

    public function testFromArrayRejectsNonBoolRateLimitByIp(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'rateLimitByIp' debe ser booleano");

        Config::fromArray(['rateLimitByIp' => 'yes']);
    }

    public function testFromArrayRejectsNonArrayTrustedProxies(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'trustedProxies' debe ser un array de IPs");

        Config::fromArray(['trustedProxies' => '10.0.0.1']);
    }

    public function testFromArrayRejectsInvalidTrustedProxy(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'trustedProxies' debe contener IPs válidas");

        Config::fromArray(['trustedProxies' => ['10.0.0.1', 'not-an-ip']]);
    }

    public function testConstructorRejectsInvalidTrustedProxy(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'trustedProxies' debe contener IPs válidas");

        new Config(trustedProxies: ['10.0.0.256']);
    }

    public function testFontDefaultsToBuiltinLargeWithAutoSize(): void
    {
        $config = new Config();

        self::assertSame(5, $config->font);
        self::assertNull($config->fontSize);
    }

    public function testConstructorKeepsFontAndFontSize(): void
    {
        $config = new Config(font: 3, fontSize: 36);

        self::assertSame(3, $config->font);
        self::assertSame(36, $config->fontSize);
    }

    public function testConstructorAcceptsNullFontSize(): void
    {
        self::assertNull((new Config(fontSize: null))->fontSize);
    }

    public function testFromArrayAcceptsFontKeys(): void
    {
        $config = Config::fromArray([
            'font' => '3',
            'fontSize' => '30',
        ]);

        self::assertSame(3, $config->font);
        self::assertSame(30, $config->fontSize);
    }

    public function testFromArrayAcceptsIntFontKeys(): void
    {
        $config = Config::fromArray(['font' => 4, 'fontSize' => 22]);

        self::assertSame(4, $config->font);
        self::assertSame(22, $config->fontSize);
    }

    public function testFromArrayRejectsNonNumericFont(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'font' debe ser un entero");

        Config::fromArray(['font' => 'grande']);
    }

    public function testFromArrayRejectsNonNumericFontSize(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'fontSize' debe ser un entero");

        Config::fromArray(['fontSize' => true]);
    }

    public function testConstructorRejectsFontBelowMinimum(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'font' debe estar entre 1 y 5");

        new Config(font: 0);
    }

    public function testConstructorRejectsFontAboveMaximum(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'font' debe estar entre 1 y 5");

        new Config(font: 6);
    }

    public function testConstructorRejectsNonPositiveFontSize(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'fontSize' debe ser un entero positivo");

        new Config(fontSize: 0);
    }

    public function testStorageDefaultsToAuto(): void
    {
        self::assertSame('auto', (new Config())->storage);
    }

    public function testFromArrayAcceptsExplicitStorageBackend(): void
    {
        $config = Config::fromArray(['storage' => 'file']);

        self::assertSame('file', $config->storage);
    }

    public function testConstructorKeepsExplicitStorageBackend(): void
    {
        self::assertSame('array', (new Config(storage: 'array'))->storage);
    }

    public function testFromArrayRejectsUnknownStorageBackend(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'storage' no es válido");

        Config::fromArray(['storage' => 'memcached']);
    }

    public function testFromArrayRejectsNonStringStorage(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'storage' debe ser texto");

        Config::fromArray(['storage' => true]);
    }

    public function testConstructorRejectsNegativeFontSize(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'fontSize' debe ser un entero positivo");

        new Config(fontSize: -4);
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $config = Config::fromArray([
            'preset' => 'strict',
            'operations' => ['+', '-'],
            'between' => [2, 20],
            'trustedProxies' => ['10.0.0.1'],
        ]);

        self::assertEquals($config, Config::fromArray($config->toArray()));
    }

    public function testToArrayExportsEffectiveValuesWithoutThePreset(): void
    {
        $array = Config::forLogin()->toArray();

        self::assertArrayNotHasKey('preset', $array);
        self::assertSame(5, $array['length']);
        self::assertSame([], $array['operations']);
        self::assertSame('low', $array['difficulty']);
        self::assertFalse($array['noise']);
        self::assertNull($array['fontSize']);
    }

    public function testToArrayExportsOperationsAsNames(): void
    {
        $config = Config::fromArray(['operations' => ['addition', 'subtraction']]);

        self::assertSame(['add', 'subtract'], $config->toArray()['operations']);
    }

    public function testMergeOverridesOnlyTheGivenKeys(): void
    {
        $base = Config::forLogin();
        $merged = $base->merge(['length' => 9]);

        self::assertSame(9, $merged->length);
        self::assertSame($base->generateAttempts, $merged->generateAttempts);
        self::assertNotSame($base, $merged);
    }

    public function testMergeAcceptsAConfigInstance(): void
    {
        $merged = Config::defaults()->merge(Config::forStrict());

        self::assertSame(Config::forStrict()->length, $merged->length);
        self::assertTrue($merged->honeypot);
    }

    public function testMergeResolvesAPresetButKeepsTheBaseKeys(): void
    {
        /*
        *  Como la base ya lleva todas las opciones efectivas, un preset
        *  dentro del parche no desplaza nada: solo las claves explícitas del
        *  parche pueden vencer a la base.
        */
        $merged = Config::defaults()->merge(['preset' => 'login']);

        self::assertSame(Config::DEFAULT_LENGTH, $merged->length);
        self::assertSame(Config::DEFAULT_VERIFY_ATTEMPTS, $merged->verifyAttempts);
    }

    public function testMergeExplicitPatchKeysWinOverTheBase(): void
    {
        $merged = Config::forLogin()->merge(['verifyAttempts' => 10]);

        self::assertSame(10, $merged->verifyAttempts);
        self::assertSame(5, $merged->length);
        self::assertFalse($merged->noise);
    }

    public function testMergeRevalidatesTheResult(): void
    {
        $this->expectException(InvalidConfigException::class);

        Config::defaults()->merge(['length' => 100]);
    }

    public function testMergeIsStrictAboutUnknownOverrideKeys(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Claves de configuración no reconocidas');

        Config::defaults()->merge(['verifyAtempts' => 5]);
    }

    public function testDefaultsFactoryMatchesConstructor(): void
    {
        self::assertEquals(new Config(), Config::defaults());
    }

    public function testForLoginMatchesTheLoginPreset(): void
    {
        self::assertEquals(Config::fromArray(['preset' => 'login']), Config::forLogin());
    }

    public function testForStrictMatchesTheStrictPreset(): void
    {
        self::assertEquals(Config::fromArray(['preset' => 'strict']), Config::forStrict());
    }
}
