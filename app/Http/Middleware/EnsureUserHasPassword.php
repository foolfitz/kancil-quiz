<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 設定頁的「安全性」（密碼、雙重驗證、passkey）只給有密碼的帳號（管理員）。老師用 Google 登入，
 * 沒有密碼，也就無法通過 Fortify 的密碼確認（docs/SPEC.md T-03）。
 */
class EnsureUserHasPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasPassword(), 404);

        return $next($request);
    }
}
