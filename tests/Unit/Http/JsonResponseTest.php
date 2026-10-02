<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Http;

use Captcha\Http\JsonResponse;
use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    public function testEncodeKeepsUnicodeAndSlashesReadable(): void
    {
        $json = JsonResponse::encode(['error' => 'No se pudo recargar', 'url' => '/a/b']);

        self::assertStringContainsString('No se pudo recargar', $json);
        self::assertStringContainsString('/a/b', $json);
    }

    public function testEncodeIsValidJson(): void
    {
        $json = JsonResponse::encode(['ok' => true, 'id' => 'abc']);

        self::assertSame(['ok' => true, 'id' => 'abc'], json_decode($json, true));
    }

    public function testHeadersDisableCachingAndEnforceJson(): void
    {
        $headers = JsonResponse::headers();

        self::assertContains('Content-Type: application/json; charset=utf-8', $headers);
        self::assertContains('Cache-Control: no-store, no-cache, must-revalidate', $headers);
        self::assertContains('Pragma: no-cache', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
    }

    public function testHeadersAddTheSecurityProtocolHeaders(): void
    {
        $headers = JsonResponse::headers();

        self::assertContains('X-Frame-Options: DENY', $headers);
        self::assertContains('Referrer-Policy: no-referrer', $headers);
    }
}
