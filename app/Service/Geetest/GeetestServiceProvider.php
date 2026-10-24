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
            [$challenge, $validate, $seccode] = array_values(request()->only([
                'geetest_challenge',
                'geetest_validate',
                'geetest_seccode',
            ]));

            $data = [
                'user_id' => Auth::id() ?: 'UnLoginUser',
                'client_type' => 'web',
                'ip_address' => request()->ip(),
            ];

            if (session()->get('gtserver') == 1) {
                return Geetest::successValidate($challenge, $validate, $seccode, $data);
            }

            return Geetest::failValidate($challenge, $validate, $seccode);
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
