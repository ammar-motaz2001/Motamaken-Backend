<?php

namespace Tests\Unit;

use App\Services\Payments\FeeCalculator;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_amounts_convert_to_cents_without_float_surprises(): void
    {
        $this->assertSame(1999, Money::toCents(19.99));
        $this->assertSame(39950, Money::toCents(399.5));
        $this->assertSame(1, Money::toCents('0.01'));
        $this->assertSame(10000, Money::toCents(100));
        $this->assertSame(12.5, Money::fromCents(1250));
    }

    public function test_the_fee_rounds_to_the_nearest_cent(): void
    {
        $fees = new FeeCalculator;

        $this->assertSame(10000, $fees->forRelease(100000, 10));
        $this->assertSame(1, $fees->forRelease(5, 10), '0.5 cents rounds up');
        $this->assertSame(0, $fees->forRelease(4, 10), '0.4 cents rounds down');
        $this->assertSame(1230, $fees->forRelease(100000, 1.234), 'percentages count to 0.01%');
        $this->assertSame(0, $fees->forRelease(100000, 0));
    }
}
