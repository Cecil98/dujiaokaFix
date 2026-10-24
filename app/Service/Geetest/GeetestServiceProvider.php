<?php

namespace App\Service\Geetest;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class GeetestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::get(config('geetest.url', 'geetest'), [GeetestController::class, 'getGeetest'])
            ->middleware('web');

        Validator::extend('geetest', function ($attribute, $value, $parameters, $validator) {
            // 显式读参数并 fail closed：缺字段时不能靠解构/弱比较“碰巧”返回，
            // 否则 PHP 8 下会抛 Undefined array key 把下单打成 500。（PQ-006）
            $payload = request()->only(['geetest_challenge', 'geetest_validate', 'geetest_seccode']);
            $challenge = $payload['geetest_challenge'] ?? null;
            $validate = $payload['geetest_validate'] ?? null;
            $seccode = $payload['geetest_seccode'] ?? null;
            if (
                !is_string($challenge) || !is_string($validate) || !is_string($seccode)
                || $challenge === '' || $validate === '' || $seccode === ''
            ) {
                return false;
            }

            $data = [
                'user_id' => Auth::id() ?: 'UnLoginUser',
                'client_type' => 'web',
                'ip_address' => request()->ip(),
            ];

            try {
                if (session()->get('gtserver') == 1) {
                    return (bool) Geetest::successValidate($challenge, $validate, $seccode, $data);
                }

                return (bool) Geetest::failValidate($challenge, $validate, $seccode);
            } catch (\Throwable $e) {
                // 极验服务不可用时不要放行，也不要 500
                \Illuminate\Support\Facades\Log::warning('Geetest validate failed: ' . $e->getMessage());

                return false;
            }
        });
    }

    public function register(): void
    {
        $this->app->singleton('geetest', function () {
            return $this->app->make(GeetestLib::class);
        });
    }

    public function provides(): array
    {
        return ['geetest'];
    }
}
