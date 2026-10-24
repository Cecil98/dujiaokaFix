<?php

namespace Tests\Unit;

use App\Http\Controllers\Pay\StripeController;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PQ-004 / PQ-004-GET follow-up: the expected USD amount must be computed on
 * the server and must stay stable for the whole payment session.
 *
 * getUsdCurrency() hits a live FX API on every call. If check()/charge()/
 * returnUrl() recomputed it, a rate tick between opening the cashier page and
 * paying would make the signed `context` token fail validation and reject a
 * perfectly legitimate payment after the buyer already paid.
 */
class StripeUsdAmountTest extends TestCase
{
    /**
     * StripeController with a deterministic stand-in for the FX lookup.
     *
     * @param string $rate
     * @return StripeController
     */
    private function controller(string $rate = '0.14'): StripeController
    {
        return new class($rate) extends StripeController {
            public $rate;

            public function __construct(string $rate)
            {
                parent::__construct();
                $this->rate = $rate;
            }

            public function getUsdCurrency($cny)
            {
                return bcmul((string) $cny, (string) $this->rate, 2);
            }
        };
    }

    private function order(string $orderSN, string $price = '10.00'): Order
    {
        $order = new Order();
        $order->setRawAttributes([
            'order_sn' => $orderSN,
            'pay_id' => 21,
            'actual_price' => $price,
        ], true);

        return $order;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function testAmountForOrderSurvivesRateChanges(): void
    {
        $order = $this->order('ORDERSTABLE0001');

        $this->assertSame(140, $this->controller('0.14')->expectedUsdAmountForOrder($order));

        // FX moves between the cashier page and the charge request.
        $this->assertSame(
            140,
            $this->controller('0.20')->expectedUsdAmountForOrder($order),
            'the amount signed into the context token must not drift during a payment session'
        );
    }

    public function testFreshOrderUsesCurrentRate(): void
    {
        $this->assertSame(140, $this->controller('0.14')->expectedUsdAmountForOrder($this->order('ORDERSTABLE0002')));
        $this->assertSame(200, $this->controller('0.20')->expectedUsdAmountForOrder($this->order('ORDERSTABLE0003')));
    }

    public function testRawConversionStillReflectsLiveRate(): void
    {
        $this->assertSame(140, $this->controller('0.14')->expectedUsdAmount('10.00'));
        $this->assertSame(200, $this->controller('0.20')->expectedUsdAmount('10.00'));
    }
}
