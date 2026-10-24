<?php

namespace App\Admin\Controllers;

use Dcat\Admin\Http\Controllers\AuthController as BaseAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends BaseAuthController
{
    /**
     * Handle a login request with per-account/IP rate limiting and a
     * default-password check.
     */
    public function postLogin(Request $request)
    {
        $key = 'admin-login:'.strtolower((string) $request->input($this->username())).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->validationErrorsResponse([
                $this->username() => '登录尝试过于频繁，请稍后再试。',
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = parent::postLogin($request);

        if ($this->guard()->check()) {
            RateLimiter::clear($key);

            $user = $this->guard()->user();
            session()->put('admin_password_checked', true);

            if ($user && password_verify('admin', (string) $user->password)) {
                session()->put('force_admin_password_change', true);
            }
        }

        return $response;
    }

    /**
     * Clear the forced password-change flag after a successful update.
     */
    public function putSetting()
    {
        $newPassword = (string) request('password');

        if ($newPassword !== '') {
            if (strlen($newPassword) < 10) {
                return $this->validationErrorsResponse([
                    'password' => '新密码至少需要 10 位',
                ]);
            }

            if ($newPassword === 'admin') {
                return $this->validationErrorsResponse([
                    'password' => '不能继续使用默认密码',
                ]);
            }
        }

        $response = parent::putSetting();

        if ($this->guard()->check()) {
            $user = $this->guard()->user();
            $user->refresh();

            if (! password_verify('admin', (string) $user->password)) {
                session()->forget('force_admin_password_change');
            }
        }

        return $response;
    }
}
