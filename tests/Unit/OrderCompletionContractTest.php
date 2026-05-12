<?php

namespace Tests\Unit;

use App\Exceptions\RuleValidationException;
use App\Service\OrderProcessService;
use Tests\TestCase;

class OrderCompletionContractTest extends TestCase
{
    public function testCompletedOrderRejectsEmptyTradeNo(): void
    {
        $this->expectException(RuleValidationException::class);

        app(OrderProcessService::class)->completedOrder('ORDER1234567890', 10.00, '');
    }
}
