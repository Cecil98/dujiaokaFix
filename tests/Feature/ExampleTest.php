<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function testOnlyEpayGatewayRoutesAreRegistered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

        $this->assertTrue($uris->contains('pay/yipay/{payway}/{orderSN}'));
        $this->assertTrue($uris->contains('pay/yipay/notify_url'));
        $this->assertFalse($uris->contains('pay/alipay/{payway}/{orderSN}'));
        $this->assertFalse($uris->contains('pay/wepay/{payway}/{orderSN}'));
        $this->assertFalse($uris->contains('pay/paypal/{payway}/{orderSN}'));
    }
}
