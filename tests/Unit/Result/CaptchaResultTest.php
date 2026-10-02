<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Result;

use Captcha\Result\CaptchaResult;
use PHPUnit\Framework\TestCase;

final class CaptchaResultTest extends TestCase
{
    private function makeResult(): CaptchaResult
    {
        return new CaptchaResult('abc123', "\x89PNG\r\n\x1a\nbytes", 'image/png');
    }

    public function testGetters(): void
    {
        $result = $this->makeResult();

        self::assertSame('abc123', $result->getId());
        self::assertSame("\x89PNG\r\n\x1a\nbytes", $result->getImage());
        self::assertSame('image/png', $result->getMimeType());
    }

    public function testGetDataUri(): void
    {
        $uri = $this->makeResult()->getDataUri();

        self::assertStringStartsWith('data:image/png;base64,', $uri);
        self::assertStringEndsWith(base64_encode("\x89PNG\r\n\x1a\nbytes"), $uri);
    }

    public function testGetHtmlEscapesAltText(): void
    {
        $html = $this->makeResult()->getHtml('a"lt');

        self::assertStringContainsString('alt="a&quot;lt"', $html);
        self::assertStringStartsWith('<img src="data:image/png;base64,', $html);
    }

    public function testGetHtmlDefaultAlt(): void
    {
        self::assertStringContainsString('alt="captcha"', $this->makeResult()->getHtml());
    }

    public function testSaveToWritesFile(): void
    {
        $path = sys_get_temp_dir() . '/captcha-result-' . bin2hex(random_bytes(4)) . '.png';

        try {
            self::assertTrue($this->makeResult()->saveTo($path));
            self::assertSame("\x89PNG\r\n\x1a\nbytes", file_get_contents($path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testSaveToReturnsFalseOnUnwritablePath(): void
    {
        self::assertFalse($this->makeResult()->saveTo('/nonexistent-dir-' . bin2hex(random_bytes(4)) . '/x.png'));
    }
}
