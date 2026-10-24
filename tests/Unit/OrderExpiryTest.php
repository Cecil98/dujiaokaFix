<?php

namespace Tests\Unit;

use App\Service\OrderProcessService;
use Tests\TestCase;

class OrderExpiryTest extends TestCase
{
    public function testExpiryMinutesAreNormalizedFromLegacySettings(): void
    {
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes('5'));
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes(5));
        $this->assertSame(12, OrderProcessService::normalizeExpiryMinutes('12'));
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes(''));
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes(null));
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes([]));
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes('0'));
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes('-10'));
        $this->assertSame(5, OrderProcessService::normalizeExpiryMinutes('abc'));
    }
}
