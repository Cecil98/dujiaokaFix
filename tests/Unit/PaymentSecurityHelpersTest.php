<?php

namespace Tests\Unit;

use App\Http\Controllers\PayController;
use App\Models\Order;
use Tests\TestCase;

class PaymentSecurityHelpersTest extends TestCase
{
    private function controller(): PayController
    {
        return new class extends PayController {
            public function routeMatches(string $actual, string $expected): bool
            {
                return $this->isExpectedGatewayRoute($actual, $expected);
            }

            public function fieldsPresent(array $data, array $fields): bool
            {
                return $this->hasRequiredFields($data, $fields);
            }

            public function equals($expected, $actual): bool
            {
                return $this->secureCompare($expected, $actual);
            }

            public function makeToken(Order $order, array $extra = []): string
            {
                return $this->buildOrderContextToken($order, $extra);
            }

            public function validToken(Order $order, ?string $token, array $extra = []): bool
            {
                return $this->validateOrderContextToken($order, $token, $extra);
            }
        };
    }

    public function testGatewayRouteComparisonIgnoresOuterSlashes(): void
    {
        $this->assertTrue($this->controller()->routeMatches('pay/vpay', '/pay/vpay'));
        $this->assertTrue($this->controller()->routeMatches('/pay/yipay/', 'pay/yipay'));
        $this->assertFalse($this->controller()->routeMatches('/pay/yipay', '/pay/mapay'));
    }

    public function testRequiredFieldsRejectMissingAndEmptyValues(): void
    {
        $controller = $this->controller();

        $this->assertTrue($controller->fieldsPresent(['sign' => 'abc', 'orderid' => 'O1'], ['sign', 'orderid']));
        $this->assertFalse($controller->fieldsPresent(['sign' => '', 'orderid' => 'O1'], ['sign', 'orderid']));
        $this->assertFalse($controller->fieldsPresent(['sign' => 'abc'], ['sign', 'orderid']));
    }

    public function testSecureCompareRequiresStringsAndExactMatch(): void
    {
        $controller = $this->controller();

        $this->assertTrue($controller->equals('abc123', 'abc123'));
        $this->assertFalse($controller->equals('abc123', 'ABC123'));
        $this->assertFalse($controller->equals('abc123', null));
    }

    public function testOrderContextTokenBindsOrderAmountPayIdAndExtraValues(): void
    {
        config(['app.key' => 'base64:'.base64_encode('01234567890123456789012345678901')]);

        $order = new Order();
        $order->order_sn = 'ORDER1234567890';
        $order->pay_id = 17;
        $order->actual_price = '10.00';

        $controller = $this->controller();
        $token = $controller->makeToken($order, ['paypal_total' => '1.38']);

        $this->assertTrue($controller->validToken($order, $token, ['paypal_total' => '1.38']));
        $this->assertFalse($controller->validToken($order, $token, ['paypal_total' => '9.99']));
    }
}
