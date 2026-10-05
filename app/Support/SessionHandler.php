<?php

namespace App\Support;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * 存在資料庫的工作階段，但不記錄 IP 與瀏覽器（docs/SPEC.md 第 11 節）。學生打開播放頁、訪客看教材時
 * 也會有工作階段，Laravel 預設會把 IP 寫進 sessions 表。在 AppServiceProvider 取代 database 這個 driver。
 */
class SessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function addRequestInformation(&$payload)
    {
        $payload = array_merge($payload, ['ip_address' => null, 'user_agent' => null]);

        return $this;
    }
}
