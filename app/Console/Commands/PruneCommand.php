<?php

namespace App\Console\Commands;

use App\Support\Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 清除過了保存期限的作答紀錄、老師刪除的資料、舊的題組版本與沒有引用的媒體（docs/SPEC.md 第 5、9 節）。
 * 排程每天執行（routes/console.php）；規則見 App\Support\Pruner。
 *
 *   php artisan kancil:prune --dry-run
 */
#[Signature('kancil:prune {--dry-run : 只列出會刪除的筆數，不刪除}')]
#[Description('清除過了保存期限的資料與沒有引用的媒體')]
class PruneCommand extends Command
{
    public function handle(Pruner $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $pruner->run($dryRun);

        $rows = [];
        foreach (Pruner::TABLES as $table => $label) {
            $rows[] = [$label, $result[$table]];
        }
        $rows[] = ['媒體檔案', $result['files']];

        $this->line(now()->setTimezone((string) config('kancil.timezone'))->toIso8601String().($dryRun ? '（dry run，沒有刪除）' : ''));
        $this->table(['資料', $dryRun ? '會刪除' : '已刪除'], $rows);

        return self::SUCCESS;
    }
}
