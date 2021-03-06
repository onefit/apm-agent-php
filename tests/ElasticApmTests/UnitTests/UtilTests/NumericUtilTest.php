<?php

declare(strict_types=1);

namespace Elastic\Apm\Tests\UnitTests\UtilTests;

use Elastic\Apm\Impl\Util\NumericUtil;
use PHPUnit\Framework\TestCase;

class NumericUtilTest extends TestCase
{
    public function testIsInClosedInterval(): void
    {
        // int - inside
        self::assertTrue(NumericUtil::isInClosedInterval(0, 1, 1));
        self::assertTrue(NumericUtil::isInClosedInterval(0, 0, 10));
        self::assertTrue(NumericUtil::isInClosedInterval(0, 1, 10));
        self::assertTrue(NumericUtil::isInClosedInterval(0, 5, 10));
        self::assertTrue(NumericUtil::isInClosedInterval(0, 10, 10));
        self::assertTrue(NumericUtil::isInClosedInterval(0, 0, 1));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, 0, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, 1, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, -1, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, 123, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, -123, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, PHP_INT_MIN + 2, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX));

        // int - outside
        self::assertTrue(!NumericUtil::isInClosedInterval(0, 2, 1));
        self::assertTrue(!NumericUtil::isInClosedInterval(0, -1, 1));
        self::assertTrue(!NumericUtil::isInClosedInterval(0, -1, 10));
        self::assertTrue(!NumericUtil::isInClosedInterval(-10, -15, 10));

        // float - inside
        self::assertTrue(NumericUtil::isInClosedInterval(-20.5, 0, 10.5));
        self::assertTrue(NumericUtil::isInClosedInterval(-20.5, -20.5, 10.5));
        self::assertTrue(NumericUtil::isInClosedInterval(-20.5, 10.5, 10.5));
        self::assertTrue(NumericUtil::isInClosedInterval(-1.2, 3.4, 3.4));
        self::assertTrue(NumericUtil::isInClosedInterval(-1.2, 3.3, 3.4));
        self::assertTrue(NumericUtil::isInClosedInterval(-1.2, -1.2, 3.4));
        self::assertTrue(NumericUtil::isInClosedInterval(-1.2, -1.1, 3.4));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, 0, PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, 1.2, PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, -1.2, PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, 123.4, PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, -123.4, PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, PHP_FLOAT_MAX - 0.1, PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, -(PHP_FLOAT_MAX - 0.1), PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, PHP_FLOAT_MAX, PHP_FLOAT_MAX));
        self::assertTrue(NumericUtil::isInClosedInterval(-PHP_FLOAT_MAX, -PHP_FLOAT_MAX, PHP_FLOAT_MAX));

        // float - outside
        self::assertTrue(!NumericUtil::isInClosedInterval(-1.2, -1.201, 3.4));
        self::assertTrue(!NumericUtil::isInClosedInterval(-1.2, 3.401, 3.4));
        self::assertTrue(!NumericUtil::isInClosedInterval(-20.5, -20.501, 10.5));
        self::assertTrue(!NumericUtil::isInClosedInterval(-20.5, 10.501, 10.5));
    }
}
