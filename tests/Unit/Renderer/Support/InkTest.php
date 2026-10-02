<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Renderer\Support;

use Captcha\Renderer\Support\Ink;
use PHPUnit\Framework\TestCase;

final class InkTest extends TestCase
{
    public function testKeepsTheTextAndShadowColorsApart(): void
    {
        $ink = new Ink(text: 111, shadow: 222);

        self::assertSame(111, $ink->text);
        self::assertSame(222, $ink->shadow);
    }

    public function testRefusesToBeRewrittenAfterConstruction(): void
    {
        $ink = new Ink(text: 1, shadow: 2);

        $this->expectException(\Error::class);

        /** @phpstan-ignore-next-line Escribiendo a propósito para probar la inmutabilidad. */
        $ink->text = 99;
    }
}
