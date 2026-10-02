<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Security;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Contract\RendererInterface;
use Captcha\Security\CaptchaGuard;
use Captcha\Storage\ArrayStorage;
use Captcha\Verification\Status;
use PHPUnit\Framework\TestCase;

final class CaptchaGuardTest extends TestCase
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

    public function testHttpStatusPerOutcome(): void
    {
        self::assertSame(200, CaptchaGuard::httpStatus(Status::Ok));
        self::assertSame(422, CaptchaGuard::httpStatus(Status::Invalid));
        self::assertSame(422, CaptchaGuard::httpStatus(Status::Expired));
        self::assertSame(422, CaptchaGuard::httpStatus(Status::Missing));
        self::assertSame(429, CaptchaGuard::httpStatus(Status::Blocked));
    }

    public function testDecideAllowsTheInitialRenderWithoutASubmission(): void
    {
        $guard = new CaptchaGuard(new Captcha(storage: new ArrayStorage(), renderer: $this->rendererStub()));

        $decision = $guard->decide([]);

        self::assertTrue($decision->allowed);
        self::assertTrue($decision->idle);
        self::assertSame(200, $decision->httpStatus);
    }

    public function testDecideRejectsARequiredSubmissionThatOmitsTheHiddenId(): void
    {
        $guard = new CaptchaGuard(new Captcha(storage: new ArrayStorage(), renderer: $this->rendererStub()));

        $decision = $guard->decide(['captcha' => '123'], requireSubmission: true);

        self::assertFalse($decision->allowed);
        self::assertFalse($decision->idle);
        self::assertSame(Status::Missing, $decision->status);
        self::assertSame(422, $decision->httpStatus);
        self::assertSame('Código captcha no encontrado.', $decision->message);
    }

    public function testDecidePassesACorrectAnswer(): void
    {
        $storage = new ArrayStorage();
        $guard = new CaptchaGuard(new Captcha(storage: $storage, renderer: $this->rendererStub()));

        $challenge = (new Captcha(storage: $storage, renderer: $this->rendererStub()))->generate();
        $expected = $storage->get($challenge->getId());

        $decision = $guard->decide(['captcha_id' => $challenge->getId(), 'captcha' => (string) $expected]);

        self::assertTrue($decision->allowed);
        self::assertFalse($decision->idle);
        self::assertSame(Status::Ok, $decision->status);
        self::assertSame(200, $decision->httpStatus);
        self::assertSame('', $decision->message);
    }

    public function testDecideRejectsAWrongAnswer(): void
    {
        $storage = new ArrayStorage();
        $guard = new CaptchaGuard(new Captcha(storage: $storage, renderer: $this->rendererStub()));

        $challenge = (new Captcha(storage: $storage, renderer: $this->rendererStub()))->generate();

        $decision = $guard->decide(['captcha_id' => $challenge->getId(), 'captcha' => '99999']);

        self::assertFalse($decision->allowed);
        self::assertSame(Status::Invalid, $decision->status);
        self::assertSame(422, $decision->httpStatus);
        self::assertSame('Código captcha incorrecto.', $decision->message);
    }

    public function testDecideTriggersTheHoneypotBeforeVerifying(): void
    {
        $storage = new ArrayStorage();
        $config = new Config(honeypot: true, honeypotField: 'website');
        $guard = new CaptchaGuard(new Captcha(storage: $storage, config: $config, renderer: $this->rendererStub()));

        $challenge = (new Captcha(storage: $storage, config: $config, renderer: $this->rendererStub()))->generate();
        $expected = $storage->get($challenge->getId());

        // Respuesta correcta pero el campo trampa va relleno: bot.
        $decision = $guard->decide([
            'captcha_id' => $challenge->getId(),
            'captcha' => (string) $expected,
            'website' => 'spam',
        ]);

        self::assertFalse($decision->allowed);
        self::assertSame(Status::Blocked, $decision->status);
        self::assertSame(429, $decision->httpStatus);
        self::assertSame('La petición parece automatizada.', $decision->message);
    }
}
