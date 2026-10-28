<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function testStripeStateChangingEndpointsArePostAndCsrfProtected(): void
    {
        foreach (['pay/stripe/check', 'pay/stripe/charge'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === $uri
            );
            $this->assertNotNull($route, $uri.' route must exist');
            $this->assertContains('POST', $route->methods(), $uri.' must not mutate on GET');
            $this->assertContains('web', $route->gatherMiddleware(), $uri.' must run the CSRF middleware');
        }
    }

    public function testOrderCreationAndEmailLookupAreThrottled(): void
    {
        foreach (['create-order', 'search-order-by-email'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === $uri
            );
            $this->assertNotNull($route, $uri.' route must exist');
            $this->assertContains('throttle:10,1', $route->gatherMiddleware(), $uri.' must be throttled');
        }
    }

    public function testEpayCallbackAndReturnAreRateLimited(): void
    {
        foreach (['pay/yipay/notify_url', 'pay/yipay/return_url'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === $uri
            );
            $this->assertNotNull($route, $uri.' route must exist');
            $this->assertTrue(
                collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'throttle:')),
                $uri.' must be throttled even though Anubis ALLOWs /pay/*'
            );
        }
    }

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
