<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_normalises_and_rounds_half_away_from_zero()
    {
        $this->assertSame('10.00', Money::of('10'));
        $this->assertSame('0.00', Money::of(null));
        $this->assertSame('1.01', Money::of('1.005'));
        $this->assertSame('-1.01', Money::of('-1.005'));
        $this->assertSame('1.00', Money::of('1.004'));
    }

    public function test_arithmetic_is_exact()
    {
        // 0.1 + 0.2 is 0.30000000000000004 in floating point.
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('-0.10', Money::sub('0.10', '0.20'));
        $this->assertSame('33.33', Money::mul('3.333', 10));
        $this->assertSame('3333.33', Money::proportion('10000.00', '1', '3'));
        $this->assertSame('99999999990.00', Money::mul('99999999.99', 1000));
    }

    public function test_comparisons()
    {
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertTrue(Money::isNegative('-0.01'));
        $this->assertSame('5.00', Money::min('5.00', '7.00'));
        $this->assertSame('7.00', Money::max('5.00', '7.00'));
        $this->assertSame('0.00', Money::proportion('10.00', '1', '0'));
    }
}
