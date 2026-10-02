<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Security;

use Captcha\Result\VerificationResult;
use Captcha\Security\GuardDecision;
use Captcha\Verification\Status;
use PHPUnit\Framework\TestCase;

final class GuardDecisionTest extends TestCase
{
    public function testIdleAllowsTheInitialRender(): void
    {
        $decision = GuardDecision::idle();

        self::assertTrue($decision->allowed);
        self::assertTrue($decision->idle);
        self::assertSame(Status::Missing, $decision->status);
        self::assertSame(200, $decision->httpStatus);
        self::assertSame('', $decision->message);
    }

    public function testMissingShortCircuitsWithAValidationStatus(): void
    {
        $decision = GuardDecision::missing();

        self::assertFalse($decision->allowed);
        self::assertFalse($decision->idle);
        self::assertSame(Status::Missing, $decision->status);
        self::assertSame(422, $decision->httpStatus);
        self::assertSame('Código captcha no encontrado.', $decision->message);
    }

    public function testFromResultMapsAnOkVerificationToAllowed(): void
    {
        $decision = GuardDecision::fromResult(new VerificationResult('id', Status::Ok, 'Código captcha correcto.'));

        self::assertTrue($decision->allowed);
        self::assertFalse($decision->idle);
        self::assertSame(200, $decision->httpStatus);
        self::assertSame('', $decision->message);
    }

    public function testFromResultMapsAFailedVerificationToDenied(): void
    {
        $decision = GuardDecision::fromResult(new VerificationResult('id', Status::Invalid, 'Código captcha incorrecto.'));

        self::assertFalse($decision->allowed);
        self::assertSame(Status::Invalid, $decision->status);
        self::assertSame(422, $decision->httpStatus);
        self::assertSame('Código captcha incorrecto.', $decision->message);
    }

    public function testFromResultMapsAnExpiredVerificationToDenied(): void
    {
        $decision = GuardDecision::fromResult(new VerificationResult('id', Status::Expired, 'El código captcha ha caducado.'));

        self::assertFalse($decision->allowed);
        self::assertSame(Status::Expired, $decision->status);
        self::assertSame(422, $decision->httpStatus);
    }

    public function testFromResultMapsABlockedVerificationToHttp429(): void
    {
        $decision = GuardDecision::fromResult(new VerificationResult('id', Status::Blocked, 'Demasiados intentos, espera unos segundos y vuelve a intentarlo.'));

        self::assertFalse($decision->allowed);
        self::assertSame(Status::Blocked, $decision->status);
        self::assertSame(429, $decision->httpStatus);
    }
}
