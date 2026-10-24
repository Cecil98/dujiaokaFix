<?php

namespace Tests\Unit;

use App\Exceptions\RuleValidationException;
use App\Rules\SearchPwd;
use App\Service\GoodsService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RuntimeSafetyTest extends TestCase
{
    public function testMissingGoodsIsRejectedWithoutTypeError(): void
    {
        $this->expectException(RuleValidationException::class);

        (new GoodsService())->validatorGoodsStatus(null);
    }

    public function testSearchPasswordRuleRejectsArraysWhenEnabled(): void
    {
        Cache::put('system-setting', ['is_open_search_pwd' => 1]);

        $rule = new SearchPwd();

        $this->assertFalse($rule->passes('search_pwd', ['not-a-string']));
        $this->assertTrue($rule->passes('search_pwd', 'secret'));

        Cache::forget('system-setting');
    }
}
