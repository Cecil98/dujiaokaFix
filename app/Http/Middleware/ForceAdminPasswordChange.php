<?php

namespace App\Http\Middleware;

use Closure;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceAdminPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Admin::guard();

        if (! $guard->check()) {
            return $next($request);
        }

        $prefix = trim((string) config('admin.route.prefix', 'admin'), '/');
        $path = trim($request->path(), '/');
        $allowed = [
            $prefix.'/auth/setting',
            $prefix.'/auth/logout',
            $prefix.'/auth/login',
        ];

        if (in_array($path, $allowed, true)) {
            return $next($request);
        }

        if (! session()->has('admin_password_checked')) {
            session()->put('admin_password_checked', true);

            $user = $guard->user();
            if ($user && password_verify('admin', (string) $user->password)) {
                session()->put('force_admin_password_change', true);
            }
        }

        if (session('force_admin_password_change')) {
            admin_warning('请立即修改后台密码', '检测到后台仍在使用默认密码，修改后才能继续操作。');

            return redirect(admin_url('auth/setting'));
        }

        return $next($request);
    }
}
