<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 不讓其他網站把頁面放進 iframe，避免點擊劫持（後台的停用帳號、設定頁的刪除帳號等都是按鈕）。
 * 播放頁例外：學生只會在上面作答，老師可能把活動嵌進自己的網站。
 */
class DenyFraming
{
    /** @var list<string> */
    private const EMBEDDABLE_ROUTES = ['play', 'curriculum.play'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->route()?->getName(), self::EMBEDDABLE_ROUTES, true)) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
