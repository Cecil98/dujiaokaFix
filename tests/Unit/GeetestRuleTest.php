<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * PQ-006 (geetest dead code): now that the validator result is actually
 * inspected, two things must hold.
 *
 * 1. Omitting `geetest_challenge` must not silently skip the check. Custom
 *    extension rules are only evaluated for attributes present in the payload,
 *    so the guard has to be `required|geetest` — otherwise automation could
 *    bypass geetest simply by not posting the field.
 * 2. The rule body itself must fail closed instead of throwing: it used to
 *    destructure `array_values(request()->only([...]))`, which raises
 *    "Undefined array key 0" (a 500 in PHP 8) for partial payloads.
 */
class GeetestRuleTest extends TestCase
{
    /** Same rules as OrderService::validatorCreateOrder(). */
    private function failsGeetest(array $payload): bool
    {
        return Validator::make($payload, [
            'geetest_challenge' => 'required|geetest',
        ], [
            'geetest' => 'geetest failed',
        ])->fails();
    }

    public function testRuleIsRegisteredAndEvaluated(): void
    {
        // Unknown rules throw BadMethodCallException, so this also guards the
        // ServiceProvider registration that PQ-006 depends on.
        $validator = Validator::make(['geetest_challenge' => 'abc'], [
            'geetest_challenge' => 'geetest',
        ], [
            'geetest' => 'geetest failed',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame('geetest failed', $validator->errors()->first('geetest_challenge'));
    }

    public function testMissingFieldCannotBypassGeetest(): void
    {
        $this->assertTrue($this->failsGeetest([]));
        $this->assertTrue($this->failsGeetest(['email' => 'buyer@example.com']));
    }

    public function testPartialPayloadFailsClosedWithoutThrowing(): void
    {
        $this->assertTrue($this->failsGeetest(['geetest_challenge' => 'abc']));
        $this->assertTrue($this->failsGeetest([
            'geetest_challenge' => 'abc',
            'geetest_validate' => 'def',
        ]));
    }

    public function testArrayPayloadFailsClosedWithoutThrowing(): void
    {
        $this->assertTrue($this->failsGeetest([
            'geetest_challenge' => ['abc'],
            'geetest_validate' => ['def'],
            'geetest_seccode' => ['ghi'],
        ]));
    }
}
