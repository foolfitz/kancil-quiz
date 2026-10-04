<?php

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
