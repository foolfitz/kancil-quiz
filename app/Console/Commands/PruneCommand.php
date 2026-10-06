<?php

namespace App\Console\Commands;

use App\Support\Pruner;
use App\Support\SystemStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * 清除過了保存期限的作答紀錄、貢獻紀錄、老師刪除的資料、舊的題組版本與沒有引用的媒體（docs/SPEC.md 第 5、9 節）。
 * 排程每天執行（routes/console.php）；規則見 App\Support\Pruner。真正執行（不是 dry run）的結果記在
 * App\Support\SystemStatus，後台的「系統狀態」頁會顯示。
 *
 *   php artisan kancil:prune --dry-run
 */
#[Signature('kancil:prune {--dry-run : 只列出會刪除的筆數，不刪除}')]
#[Description('清除過了保存期限的資料與沒有引用的媒體')]
class PruneCommand extends Command
{
    public function handle(Pruner $pruner, SystemStatus $status): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $pruner->run($dryRun);
        } catch (Throwable $e) {
            // 失敗的原因連同 stack trace 進記錄（正式環境是 docker compose logs scheduler），
            // 排程的輸出檔（prune.log）與系統狀態頁只留訊息
            report($e);
            if (! $dryRun) {
                $status->recordPruneFailure($e);
            }
            $this->error('kancil:prune 失敗：'.$e->getMessage());

            return self::FAILURE;
        }

        if (! $dryRun) {
            $status->recordPrune($result);
        }

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
