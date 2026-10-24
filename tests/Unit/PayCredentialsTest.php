<?php

namespace Tests\Unit;

use App\Models\Pay;
use Tests\TestCase;

/**
 * Regression tests for PQ-01: factory placeholder credentials must never be
 * considered usable, otherwise anyone can forge epay (yipay) notifications.
 */
class PayCredentialsTest extends TestCase
{
    /**
     * Build a Pay model without touching the database and without encrypting
     * the values (setRawAttributes bypasses the EncryptedOrPlaintext setter).
     */
    private function rawPay(array $attributes): Pay
    {
        $pay = new Pay();
        $pay->setRawAttributes($attributes, true);

        return $pay;
    }

    private function configuredYipay(array $overrides = []): Pay
    {
        return $this->rawPay(array_merge([
            'id' => 14,
            'pay_name' => '易支付-支付宝',
            'pay_check' => 'alipay',
            'pay_client' => 1,
            'merchant_id' => '1001',
            'merchant_key' => 'https://pay.example.com/submit.php',
            'merchant_pem' => 'a-real-signing-key',
            'pay_handleroute' => '/pay/yipay',
            'is_open' => 1,
        ], $overrides));
    }

    public function testConfiguredYipayCredentialsAreUsable(): void
    {
        $this->assertTrue($this->configuredYipay()->hasUsableYipayCredentials());
    }

    public function testHandlerouteIsNormalized(): void
    {
        $this->assertTrue(
            $this->configuredYipay(['pay_handleroute' => 'pay/yipay/'])->hasUsableYipayCredentials()
        );
    }

    public function testPlaceholderMerchantPemIsRejected(): void
    {
        $this->assertFalse(
            $this->configuredYipay(['merchant_pem' => Pay::PLACEHOLDER_MERCHANT_PEM])->hasUsableYipayCredentials()
        );
    }

    public function testPlaceholderMerchantIdIsRejected(): void
    {
        $this->assertFalse(
            $this->configuredYipay(['merchant_id' => Pay::PLACEHOLDER_MERCHANT_ID])->hasUsableYipayCredentials()
        );
    }

    public function testEmptyMerchantIdIsRejected(): void
    {
        $this->assertFalse(
            $this->configuredYipay(['merchant_id' => '  '])->hasUsableYipayCredentials()
        );
    }

    public function testMissingGatewayUrlIsRejected(): void
    {
        $this->assertFalse($this->configuredYipay(['merchant_key' => ''])->hasUsableYipayCredentials());
        $this->assertFalse($this->configuredYipay(['merchant_key' => null])->hasUsableYipayCredentials());
    }

    public function testNonHttpGatewayUrlIsRejected(): void
    {
        $this->assertFalse(
            $this->configuredYipay(['merchant_key' => 'javascript:alert(1)'])->hasUsableYipayCredentials()
        );
        $this->assertFalse(
            $this->configuredYipay(['merchant_key' => 'ftp://pay.example.com/submit'])->hasUsableYipayCredentials()
        );
        $this->assertFalse(
            $this->configuredYipay(['merchant_key' => 'pay.example.com/submit'])->hasUsableYipayCredentials()
        );
    }

    public function testEmptyMerchantPemIsRejected(): void
    {
        $this->assertFalse($this->configuredYipay(['merchant_pem' => ''])->hasUsableYipayCredentials());
    }

    public function testNonYipayRouteIsRejected(): void
    {
        $this->assertFalse(
            $this->configuredYipay(['pay_handleroute' => '/pay/mapay'])->hasUsableYipayCredentials()
        );
        $this->assertFalse(
            $this->configuredYipay(['pay_handleroute' => null])->hasUsableYipayCredentials()
        );
    }

    public function testSeedRowsFromInstallSqlAreRejected(): void
    {
        // Exactly the rows shipped in database/sql/install.sql.
        $seedRows = [
            $this->rawPay([
                'merchant_id' => '商户号',
                'merchant_key' => '',
                'merchant_pem' => '密钥',
                'pay_handleroute' => '/pay/yipay',
            ]),
            $this->rawPay([
                'merchant_id' => '商户号',
                'merchant_key' => null,
                'merchant_pem' => '密钥',
                'pay_handleroute' => '/pay/yipay',
            ]),
        ];

        foreach ($seedRows as $pay) {
            $this->assertFalse($pay->hasUsableYipayCredentials());
        }
    }

    public function testIsHttpUrlHelper(): void
    {
        $this->assertTrue(Pay::isHttpUrl('http://pay.example.com'));
        $this->assertTrue(Pay::isHttpUrl('https://pay.example.com/submit.php?a=1'));
        $this->assertFalse(Pay::isHttpUrl(''));
        $this->assertFalse(Pay::isHttpUrl('https://'));
        $this->assertFalse(Pay::isHttpUrl('//pay.example.com'));
        $this->assertFalse(Pay::isHttpUrl('file:///etc/passwd'));
    }
}
