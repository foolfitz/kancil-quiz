<?php

use App\Support\SystemStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 清除過了保存期限的資料（docs/SPEC.md 第 5、9 節）。排在每天凌晨 3 點的備份之後（docs/deploy.md），
// 誤刪時前一天的備份還有。正式環境由 scheduler 服務執行 schedule:work（compose.yaml）。
Schedule::command('kancil:prune')
    ->dailyAt('04:00')
    ->timezone(config('kancil.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/prune.log'));

// 排程還活著的訊號：後台的「系統狀態」頁超過 1 小時沒有看到就警告（App\Support\SystemStatus）
Schedule::call(fn () => app(SystemStatus::class)->recordHeartbeat())
    ->name('kancil:heartbeat')
    ->everyFifteenMinutes();
