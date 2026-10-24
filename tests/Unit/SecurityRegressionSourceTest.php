<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Source-level regression guards for the static security audit findings that
 * cannot be exercised meaningfully without MySQL (row locks, CAS updates,
 * anonymous lookup hardening). The goal is to fail loudly if the predicate is
 * removed again, without introducing brittle DB integration tests.
 *
 * Findings covered: PQ-01, PQ-02, PQ-04, PQ-05, PQ-06-A/B/C, PQ-08-F1.
 */
class SecurityRegressionSourceTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function assertSourceContains(string $relativePath, array $needles): void
    {
        $source = $this->source($relativePath);
        foreach ($needles as $needle) {
            $this->assertStringContainsString(
                $needle,
                $source,
                $relativePath . ' no longer contains: ' . $needle
            );
        }
    }

    public function testCouponDecrementIsAtomicAndChecksAffectedRows(): void
    {
        $this->assertSourceContains('app/Service/CouponService.php', [
            "->where('ret', '>', 0)",
            "decrement('ret', 1)",
            'public function retDecr(string $coupon): int',
        ]);

        $this->assertSourceContains('app/Service/OrderProcessService.php', [
            '$affected = $this->couponService->retDecr(',
            'if ($affected < 1) {',
        ]);
    }

    public function testCarmisSelectionIsLockedAndOrdered(): void
    {
        $this->assertSourceContains('app/Service/CarmisService.php', [
            "->where('is_loop', 0)",
            "->orderBy('id')",
            '->lockForUpdate()',
        ]);
    }

    public function testCarmisSoldUpdateUsesCompareAndSetPredicate(): void
    {
        $this->assertSourceContains('app/Service/CarmisService.php', [
            'public function soldByIDS(array $ids): int',
            "->where('status', Carmis::STATUS_UNSOLD)",
        ]);

        $this->assertSourceContains('app/Service/OrderProcessService.php', [
            '$sold = $this->carmisService->soldByIDS($ids);',
            'if ($sold !== count($ids)) {',
        ]);
    }

    public function testStockDecrementIsConditionalAndAsserted(): void
    {
        $this->assertSourceContains('app/Service/GoodsService.php', [
            'public function inStockDecr(int $id, int $number = 1): int',
            "->where('in_stock', '>=', \$number)",
        ]);

        $this->assertSourceContains('app/Service/OrderProcessService.php', [
            'if ($this->goodsService->inStockDecr($order->goods_id, $order->buy_amount) < 1) {',
        ]);
    }

    public function testOrderReadsUseRowLocks(): void
    {
        $this->assertSourceContains('app/Service/OrderService.php', [
            'public function detailOrderSN(string $orderSN, bool $lockForUpdate = false):? Order',
            '->lockForUpdate()',
        ]);

        $this->assertSourceContains('app/Service/OrderProcessService.php', [
            '$this->orderService->detailOrderSN($orderSN, true)',
        ]);
    }

    public function testOrderExpiryOnlyTouchesWaitPayOrders(): void
    {
        $this->assertSourceContains('app/Service/OrderService.php', [
            "->where('status', Order::STATUS_WAIT_PAY)",
            'return $affected > 0;',
        ]);

        $this->assertSourceContains('app/Jobs/OrderExpired.php', [
            'if (app(\'Service\\OrderService\')->expiredOrderSN($this->orderSN)) {',
        ]);
    }

    public function testYipayPlaceholderCredentialsAreRejectedEverywhere(): void
    {
        $this->assertSourceContains('app/Models/Pay.php', [
            'public function hasUsableYipayCredentials(): bool',
            "const PLACEHOLDER_MERCHANT_ID = '商户号';",
            "const PLACEHOLDER_MERCHANT_PEM = '密钥';",
        ]);

        $this->assertSourceContains('app/Http/Controllers/Pay/YipayController.php', [
            'if (!$this->payGateway->hasUsableYipayCredentials()) {',
            'if (!$payGateway->hasUsableYipayCredentials()) {',
        ]);

        $this->assertSourceContains('app/Service/OrderService.php', [
            '$this->validatorSelectedPay((int) $request->input(\'payway\'));',
            '$pay->isYipayGateway() && !$pay->hasUsableYipayCredentials()',
        ]);
    }

    public function testFactoryDataDoesNotAutoEnableYipay(): void
    {
        $install = $this->activeSql($this->source('database/sql/install.sql'));
        $this->assertStringContainsString('UPDATE `pays` SET `is_open` = 0;', $install);
        $this->assertStringNotContainsString(
            'UPDATE `pays` SET `is_open` = 1',
            $install,
            'install.sql must not enable any payment channel with placeholder credentials'
        );

        $upgrade = $this->activeSql($this->source('database/sql/upgrade-php83.sql'));
        $this->assertStringContainsString('UPDATE `pays`', $upgrade);
        $this->assertStringContainsString('SET `is_open` = 0', $upgrade);
        $this->assertStringContainsString("TRIM(`merchant_id`) IN ('', '商户号')", $upgrade);
        $this->assertStringContainsString("TRIM(`merchant_pem`) IN ('', '密钥')", $upgrade);
        $this->assertStringContainsString("TRIM(`merchant_key`) = ''", $upgrade);
    }

    /**
     * Strip `--` comment lines so assertions only look at executable SQL.
     */
    private function activeSql(string $sql): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    public function testAnonymousEmailLookupRequiresOwnershipProof(): void
    {
        $this->assertSourceContains('app/Service/OrderService.php', [
            'public function withEmailAndPassword(string $email, string $searchPwd = \'\', array $orderSNS = [])',
            "if (\$searchPwd === '' && empty(\$orderSNS)) {",
            "->where('search_pwd', \$searchPwd)",
            "->whereIn('order_sn', \$orderSNS)",
        ]);

        $this->assertSourceContains('app/Http/Controllers/Home/OrderController.php', [
            'filter_var($email, FILTER_VALIDATE_EMAIL)',
            '$orderSNS = $this->cookieOrderSNS();',
            'if ($searchPwd === \'\' && empty($orderSNS)) {',
        ]);

        $this->assertSourceContains('routes/common/web.php', [
            "->middleware('throttle:10,1')",
        ]);
    }

    public function testOrderCookieCannotCrashAfterCommit(): void
    {
        $this->assertSourceContains('app/Http/Controllers/Home/OrderController.php', [
            "if (! is_array(\$cookies)) {",
            'Cookie::queue(\'dujiaoka_orders\', json_encode(array_values(array_unique($cookies))));',
        ]);
    }

    public function testCarmisImportDoesNotDeleteNullUploadPath(): void
    {
        $this->assertSourceContains('app/Admin/Forms/ImportCarmis.php', [
            "is_scalar(\$input['carmis_txt'] ?? null)",
            "if (\$carmisTxt !== '') {",
            'Storage::disk(\'public\')->delete($carmisTxt);',
        ]);
        $this->assertStringNotContainsString(
            "Storage::disk('public')->delete(\$input['carmis_txt'])",
            $this->source('app/Admin/Forms/ImportCarmis.php'),
            'carmis_txt may be null; never pass it directly to Flysystem delete()'
        );
    }

    public function testGeetestValidationActuallyChecksFails(): void
    {
        $this->assertSourceContains('app/Service/OrderService.php', [
            '$geetestValidator = Validator::make(',
            'if ($geetestValidator->fails()) {',
            // 扩展规则对缺失字段是跳过的，没有 required 就等于没有校验
            "['geetest_challenge' => 'required|geetest']",
        ]);
        $this->assertStringNotContainsString(
            '!Validator::make(',
            $this->source('app/Service/OrderService.php'),
            'geetest validation must not treat the Validator object as a boolean'
        );
    }

    public function testStripeCheckUsesServerSideAmountAndRequiresContext(): void
    {
        $this->assertSourceContains('app/Http/Controllers/Pay/StripeController.php', [
            "if (!\$this->hasRequiredFields(\$data, ['orderid', 'source', 'context'])) {",
            '$stripeUsdAmount = $this->expectedUsdAmountForOrder($cacheord);',
            "['stripe_usd_amount' => \$stripeUsdAmount]",
            'public function expectedUsdAmount($cny): int',
            'public function expectedUsdAmountForOrder(Order $order): int',
        ]);

        // The client-supplied amount must no longer be read as authority.
        $this->assertStringNotContainsString(
            "\$request->input(\n                'stripe_usd_amount'",
            $this->source('app/Http/Controllers/Pay/StripeController.php')
        );
    }

    public function testEnvExampleDoesNotForceInsecureSessionCookie(): void
    {
        $this->assertStringNotContainsString(
            'SESSION_SECURE_COOKIE=false',
            $this->source('.env.example'),
            '.env.example must leave SESSION_SECURE_COOKIE unset so HTTPS is auto-detected'
        );
    }

    public function testEmptyPaymentChannelListReturnsArrayForViews(): void
    {
        // install.sql now ships every channel disabled, so the buy view can get
        // an empty list. `@foreach($payways ...)` over null is an E_WARNING on
        // PHP 8 and would 500 the buy page of every fresh install.
        $this->assertSourceContains('app/Service/PayService.php', [
            'public function pays(string $payClient = Pay::PAY_CLIENT_PC): array',
            'return $payGateway->toArray();',
        ]);
        $this->assertStringNotContainsString(
            '$payGateway ? $payGateway->toArray() : null',
            $this->source('app/Service/PayService.php')
        );
    }
}
