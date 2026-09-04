<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\ServicePaymentTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServicePaymentTermsTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, int, int}> */
    public static function validTerms(): iterable
    {
        yield 'no payment' => [ServicePaymentTerms::NONE, 0, 9999, 0, 9999];
        yield 'full payment' => [ServicePaymentTerms::FULL, 0, 9999, 9999, 0];
        yield 'fixed deposit' => [ServicePaymentTerms::FIXED, 2500, 9999, 2500, 7499];
        yield 'fixed deposit capped at total' => [ServicePaymentTerms::FIXED, 12000, 9999, 9999, 0];
        yield 'percentage rounds half up to a cent' => [ServicePaymentTerms::PERCENTAGE, 30, 9999, 3000, 6999];
    }

    #[DataProvider('validTerms')]
    public function testAmountsStayInCents(string $mode, int $value, int $total, int $dueNow, int $balance): void
    {
        $result = (new ServicePaymentTerms())->calculateAmounts($mode, $value, $total);

        self::assertSame($dueNow, $result['dueNow']);
        self::assertSame($balance, $result['balanceDue']);
        self::assertSame($total, $result['dueNow'] + $result['balanceDue']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidTerms(): iterable
    {
        yield 'unknown mode' => ['later', 0];
        yield 'zero fixed deposit' => [ServicePaymentTerms::FIXED, 0];
        yield 'percentage over one hundred' => [ServicePaymentTerms::PERCENTAGE, 101];
        yield 'negative value' => [ServicePaymentTerms::NONE, -1];
    }

    #[DataProvider('invalidTerms')]
    public function testInvalidRuleIsRejected(string $mode, int $value): void
    {
        $this->expectException(\DomainException::class);
        (new ServicePaymentTerms())->calculateAmounts($mode, $value, 10000);
    }
}
