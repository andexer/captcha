<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Widget;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Contract\RateLimiterInterface;
use Captcha\Contract\RendererInterface;
use Captcha\Storage\ArrayStorage;
use Captcha\Widget\Widget;
use Captcha\Widget\WidgetModel;
use Captcha\Widget\WidgetOptions;
use PHPUnit\Framework\TestCase;

final class WidgetTest extends TestCase
{
    private function rendererStub(): RendererInterface
    {
        return new class implements RendererInterface {
            public function render(string $code, Config $config): string
            {
                return "\x89PNG\r\n\x1a\nfake";
            }

            public function mimeType(): string
            {
                return 'image/png';
            }
        };
    }

    private function captcha(Config $config = new Config(), ArrayStorage $storage = new ArrayStorage()): Captcha
    {
        /*
        *  Estos tests miden markup, no presupuesto: sin limiter inyectado la
        *  instancia usaría el límite por defecto y el widget degradaría.
        */
        return new Captcha(
            storage: $storage,
            config: $config,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );
    }

    private function rateLimiterOff(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            public function allow(string $key, int $limit, int $windowSeconds): bool
            {
                return true;
            }
        };
    }

    public function testRenderBuildsFullWidgetMarkup(): void
    {
        $html = $this->captcha()->widget();

        self::assertStringContainsString('data-captcha', $html);
        self::assertStringContainsString('data-endpoint="/captcha/endpoint"', $html);
        self::assertStringContainsString('data-theme="light"', $html);
        self::assertStringContainsString('class="ct__image"', $html);
        self::assertStringContainsString('data:image/png;base64,', $html);
        self::assertStringContainsString('class="ct__reload"', $html);
        self::assertStringContainsString('type="hidden" name="captcha_id"', $html);
        self::assertStringContainsString('type="text" name="captcha"', $html);
    }

    public function testRenderUsesConfigLengthAndDimensions(): void
    {
        $config = new Config(length: 4, width: 300, height: 100);
        $html = $this->captcha($config)->widget();

        self::assertStringContainsString('maxlength="4"', $html);
        self::assertStringContainsString('width="300"', $html);
        self::assertStringContainsString('height="100"', $html);
    }

    public function testRenderPersistsAConsumableChallenge(): void
    {
        $storage = new ArrayStorage();
        $html = $this->captcha(storage: $storage)->widget();

        preg_match('/name="captcha_id" value="([a-f0-9]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1]);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $storage->consume($matches[1]));
    }

    public function testRenderEscapesEndpointAttribute(): void
    {
        $html = $this->captcha()->widget(['endpoint' => '"><script>alert(1)</script>']);

        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function testRenderAppliesRequestedTheme(): void
    {
        $html = $this->captcha()->widget(['theme' => 'dark']);

        self::assertStringContainsString('data-theme="dark"', $html);
    }

    public function testRenderFallsBackToLightForUnknownTheme(): void
    {
        $html = $this->captcha()->widget(['theme' => 'neon']);

        self::assertStringContainsString('data-theme="light"', $html);
    }

    public function testRenderStillSupportsSystemPreferenceWhenAutoIsRequested(): void
    {
        $html = $this->captcha()->widget(['theme' => 'auto']);

        self::assertStringContainsString('data-theme="auto"', $html);
    }

    public function testRenderEscapesThemeAttribute(): void
    {
        $html = $this->captcha()->widget(['theme' => '"><script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function testRenderUsesCustomFieldNamesFromConfig(): void
    {
        $html = $this->captcha(new Config(idField: 'challenge_id', inputField: 'code'))->widget();

        self::assertStringContainsString('type="text" name="code"', $html);
        self::assertStringContainsString('type="hidden" name="challenge_id"', $html);
    }

    public function testRenderIncludesHoneypotWhenEnabled(): void
    {
        $html = $this->captcha(new Config(honeypot: true, honeypotField: 'website'))->widget();

        self::assertStringContainsString('class="ct__honeypot"', $html);
        self::assertStringContainsString('type="text" name="website"', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
    }

    public function testRenderOmitsHoneypotWhenDisabled(): void
    {
        $html = $this->captcha()->widget();

        /*
        *  El CSS inyectado contiene el selector de la trampa aunque esté
        *  apagada; lo que no debe aparecer es el campo de formulario.
        */
        self::assertStringNotContainsString('name="email"', $html);
        self::assertStringNotContainsString('type="text" name="website"', $html);
    }

    public function testRenderIncludesSpanishLabels(): void
    {
        $html = $this->captcha()->widget();

        self::assertStringContainsString('Recargar captcha', $html);
        self::assertStringContainsString('Código captcha', $html);
    }

    public function testRenderShowsPlaceholderInCodeInput(): void
    {
        $html = $this->captcha()->widget();

        self::assertStringContainsString('placeholder="Código"', $html);
    }

    public function testWidgetInjectsAssetsOnlyOnce(): void
    {
        $captcha = $this->captcha();

        $page = $captcha->widget() . $captcha->widget();

        self::assertSame(1, substr_count($page, '<style>'));
        self::assertSame(1, substr_count($page, '<script>'));
    }

    public function testWidgetOmitsAssetsWhenInjectionDisabled(): void
    {
        $html = $this->captcha(new Config(injectAssets: false))->widget();

        self::assertStringNotContainsString('<style>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('data-captcha', $html);
    }

    public function testAssetsInlineModeReturnsCssAndJs(): void
    {
        $html = $this->captcha()->assets();

        self::assertStringContainsString('<style>', $html);
        self::assertStringContainsString('<script>', $html);
    }

    public function testAssetsUrlModePointsAtBaseUrl(): void
    {
        $html = $this->captcha()->assets('url', '/mi-captcha');

        self::assertStringContainsString('<link rel="stylesheet" href="/mi-captcha/captcha.min.css">', $html);
        self::assertStringContainsString('<script src="/mi-captcha/captcha.min.js"></script>', $html);
    }

    public function testAssetsUrlModeRejectsEmptyBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Se requiere una URL base');

        $this->captcha()->assets('url', '');
    }

    public function testAssetsRejectsUnsupportedMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Modo de assets');

        $this->captcha()->assets('csp');
    }

    public function testErrorStateRendersOnlyWrapperAndEscapedMessage(): void
    {
        $widget = new Widget(
            WidgetModel::fromConfig(new Config()),
            new WidgetOptions(endpoint: '/captcha/generate'),
            null,
            'Demasiados captchas generados <script>alert(1)</script>',
        );

        $html = $widget->render();

        self::assertStringContainsString('data-captcha', $html);
        self::assertStringContainsString('data-endpoint="/captcha/generate"', $html);
        self::assertStringContainsString('Demasiados captchas generados &lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('ct__image', $html);
        self::assertStringNotContainsString('ct__input', $html);
        self::assertStringNotContainsString('name="captcha_id"', $html);
    }

    public function testWidgetRejectsUnknownOptionWithSpanishMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Opción de widget desconocida: 'temap'.");

        $this->captcha()->widget(['temap' => 'dark']);
    }

    public function testWidgetRejectsNonStringOptionValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("La opción de widget 'theme' debe ser texto.");

        $this->captcha()->widget(['theme' => ['dark']]);
    }
}
