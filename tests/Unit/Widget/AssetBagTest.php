<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Widget;

use Captcha\Widget\AssetBag;
use PHPUnit\Framework\TestCase;

final class AssetBagTest extends TestCase
{
    private string $tempJs = '';
    private string $tempCss = '';

    protected function tearDown(): void
    {
        @unlink($this->tempJs);
        @unlink($this->tempCss);
    }

    private function bag(string $js = 'var x = 1;', string $css = '.a{}'): AssetBag
    {
        $this->tempJs = tempnam(sys_get_temp_dir(), 'pcjs');
        $this->tempCss = tempnam(sys_get_temp_dir(), 'pccss');
        file_put_contents($this->tempJs, $js);
        file_put_contents($this->tempCss, $css);

        return new AssetBag($this->tempCss, $this->tempJs);
    }

    public function testInlineReturnsStyleAndScript(): void
    {
        $html = $this->bag()->inline();

        self::assertStringContainsString('<style>.a{}</style>', $html);
        self::assertStringContainsString('<script>var x = 1;</script>', $html);
    }

    public function testOnceEmitsOnlyTheFirstTime(): void
    {
        $bag = $this->bag();

        self::assertStringContainsString('<style>', $bag->once());
        self::assertSame('', $bag->once());
    }

    public function testInlineIgnoresDedupFlag(): void
    {
        $bag = $this->bag();
        $bag->once();

        self::assertStringContainsString('<style>', $bag->inline());
    }

    public function testClosingScriptSequenceIsEscaped(): void
    {
        $bag = $this->bag(js: 'var s = "</script>";');

        $html = $bag->inline();

        self::assertStringContainsString('<\/script>', $html);
        self::assertSame(1, substr_count($html, '</script>'), 'Solo la etiqueta envoltorio puede cerrar');
    }

    public function testUnreadableAssetThrows(): void
    {
        // file_get_contents() emite un E_WARNING esperado al fallar.
        set_error_handler(static fn(int $severity, string $message): bool => true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No se puede leer el asset del widget');

            (new AssetBag('/missing/captcha.css', '/missing/captcha.js'))->inline();
        } finally {
            restore_error_handler();
        }
    }
}
