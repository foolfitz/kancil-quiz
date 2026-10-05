<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理員停用的帳號（docs/SPEC.md A-05）：已經登入的也在下一個請求登出。
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDisabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withErrors(['google' => '這個帳號已經停用。如有疑問，請聯絡網站管理員。']);
        }

        return $next($request);
    }
}
