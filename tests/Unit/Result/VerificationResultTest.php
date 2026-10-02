<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Result;

use Captcha\Result\VerificationResult;
use Captcha\Verification\Status;
use PHPUnit\Framework\TestCase;

final class VerificationResultTest extends TestCase
{
    public function testOkResultIsValid(): void
    {
        $result = new VerificationResult('id-1', Status::Ok, 'Código captcha correcto.');

        self::assertTrue($result->isValid());
        self::assertSame(Status::Ok, $result->getStatus());
        self::assertSame('id-1', $result->getId());
        self::assertSame('Código captcha correcto.', $result->getMessage());
    }

    public function testNonOkResultsAreInvalid(): void
    {
        foreach ([Status::Invalid, Status::Expired, Status::Missing] as $status) {
            $result = new VerificationResult('id-1', $status, 'msg');

            self::assertFalse($result->isValid());
            self::assertSame($status, $result->getStatus());
        }
    }
}
