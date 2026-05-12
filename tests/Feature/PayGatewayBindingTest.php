<?php

namespace Tests\Feature;

use App\Http\Controllers\PayController;
use Tests\TestCase;

class PayGatewayBindingTest extends TestCase
{
    public function testLegacyGatewayRouteIgnoresHandleParameter(): void
    {
        $controller = new class extends PayController {
            public $calledWith = [];

            public function redirectGateway(string $payway, string $orderSN)
            {
                $this->calledWith = [$payway, $orderSN];

                return response('ok');
            }
        };

        $response = $controller->redirectGatewayLegacy('/pay/evil', 'payjswescan', 'ORDER1234567890');

        $this->assertSame(['payjswescan', 'ORDER1234567890'], $controller->calledWith);
        $this->assertSame('ok', $response->getContent());
    }
}
