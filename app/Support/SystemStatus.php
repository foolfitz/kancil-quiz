<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Throwable;

/**
 * 系統狀態（docs/SPEC.md A-02）：給後台的「系統狀態」頁（App\Filament\Pages\SystemStatus）看儲存空間、
 * 備份、資料的清除與排程。不裝監控軟體，只讀每件事留下的紀錄：
 *
 * 備份、kancil:prune 與排程各把最近一次的結果寫成 local disk 上 status/ 目錄中的一個 JSON 檔
 * （正式環境在 volume 中，storage/app/private/status/）：
 *
 * - backup.json：主機上的 docker/backup.sh 備份完成或失敗時，用 docker compose exec 寫進容器。
 * - prune.json：kancil:prune 真正執行後寫入（dry run 不算），成功時附上刪除的筆數。
 * - scheduler.json：排程每 15 分鐘寫一次（routes/console.php），用來看 scheduler 服務還在不在。
 *
 * 每個檔案都是 {"at": ISO 8601 的 UTC 時間, "ok": bool, ...}。備份與清除都是每天一次，
 * 超過 26 小時沒有新的紀錄就警告；排程超過 1 小時沒有回報就當作停了。
 */
class SystemStatus
{
    public const DIR = 'status';

    public const BACKUP_FILE = self::DIR.'/backup.json';

    public const PRUNE_FILE = self::DIR.'/prune.json';

    public const SCHEDULER_FILE = self::DIR.'/scheduler.json';

    /** 每天一次的工作，超過幾小時沒有紀錄就警告 */
    public const DAILY_MAX_AGE_HOURS = 26;

    /** 排程多久回報一次，以及超過多久沒有回報就當作停了 */
    public const HEARTBEAT_MINUTES = 15;

    public const HEARTBEAT_MAX_AGE_MINUTES = 60;

    /** 磁碟剩餘空間低於這個比例時提醒、更低時警告 */
    private const DISK_WARNING_RATIO = 0.10;

    private const DISK_DANGER_RATIO = 0.05;

    /**
     * kancil:prune 執行成功。
     *
     * @param  array<string, int>  $deleted  Pruner::run() 的結果
     */
    public function recordPrune(array $deleted): void
    {
        $this->write(self::PRUNE_FILE, ['ok' => true, 'deleted' => $deleted]);
    }

    public function recordPruneFailure(Throwable $e): void
    {
        $this->write(self::PRUNE_FILE, ['ok' => false, 'error' => $e->getMessage()]);
    }

    public function recordHeartbeat(): void
    {
        $this->write(self::SCHEDULER_FILE, ['ok' => true]);
    }

    public function storage(): StatusCheck
    {
        $root = storage_path();
        ['total' => $total, 'free' => $free] = $this->diskSpace();
        $media = $this->mediaSize();
        $database = $this->databaseSize();

        $details = [
            '上傳的媒體' => sprintf('%s（%d 個檔案）', self::bytes($media['bytes']), $media['files']),
            '資料庫' => $database === null ? '—' : self::bytes($database).'（SQLite，含 WAL）',
        ];

        if ($total === false || $free === false || $total <= 0) {
            return new StatusCheck(Health::Warning, '讀不到磁碟空間', "storage 目錄：{$root}", details: $details);
        }

        $ratio = $free / $total;
        $disk = sprintf('剩餘 %s，共 %s（剩 %d%%）', self::bytes($free), self::bytes($total), (int) round($ratio * 100));
        $details = ['磁碟' => $disk] + $details;

        if ($ratio < self::DISK_DANGER_RATIO) {
            return new StatusCheck(Health::Danger, '磁碟快滿了：'.$disk, '空間用完時上傳與備份都會失敗，請擴充磁碟或清掉主機上不用的東西（例如舊的 Docker 映像檔：docker image prune）。', details: $details);
        }
        if ($ratio < self::DISK_WARNING_RATIO) {
            return new StatusCheck(Health::Warning, '磁碟剩餘空間不多：'.$disk, '請留意，必要時擴充磁碟或清掉主機上不用的東西。', details: $details);
        }

        return new StatusCheck(Health::Ok, '磁碟空間足夠：'.$disk, details: $details);
    }

    /**
     * 主機上的 docker/backup.sh 每天凌晨 3 點執行（docs/deploy.md）。
     */
    public function backup(): StatusCheck
    {
        $advice = '請看主機上的 backup.log（crontab 中備份指令輸出的檔案），並手動執行一次 docker/backup.sh。';

        return $this->daily(
            self::BACKUP_FILE,
            '備份',
            '備份要在主機的 crontab 設定（docs/deploy.md「備份與還原」）。設定好之後，每次備份的結果會顯示在這裡。',
            $advice,
            fn (array $record): array => array_filter([
                '檔案' => is_string($record['file'] ?? null) ? $record['file'] : null,
                '大小' => is_numeric($record['bytes'] ?? null) ? self::bytes((int) $record['bytes']) : null,
                '保留的份數' => is_numeric($record['kept'] ?? null) ? (string) (int) $record['kept'] : null,
            ], fn (?string $value) => $value !== null),
        );
    }

    /**
     * scheduler 服務每天凌晨 4 點執行 kancil:prune（routes/console.php）。
     */
    public function prune(): StatusCheck
    {
        return $this->daily(
            self::PRUNE_FILE,
            '資料的清除',
            'scheduler 服務每天凌晨 4 點執行 kancil:prune，執行過之後結果會顯示在這裡。可以先用 docker compose exec scheduler php artisan kancil:prune --dry-run 看看會刪除多少。',
            '請看 docker compose logs scheduler 與 volume 中的 storage/logs/prune.log。',
            function (array $record): array {
                $deleted = $record['deleted'] ?? null;
                if (! is_array($deleted)) {
                    return [];
                }
                $parts = [];
                foreach (Pruner::TABLES + ['files' => '媒體檔案'] as $table => $label) {
                    $parts[] = $label.' '.(int) ($deleted[$table] ?? 0);
                }

                return ['刪除的筆數' => implode('、', $parts)];
            },
        );
    }

    /**
     * scheduler 服務（compose.yaml）執行 schedule:work，排程每 15 分鐘回報一次。
     */
    public function scheduler(): StatusCheck
    {
        $advice = '請在主機上執行 docker compose ps 確認 scheduler 容器在執行，並看 docker compose logs scheduler。';
        $record = $this->read(self::SCHEDULER_FILE);

        if ($record === null) {
            return new StatusCheck(Health::Warning, '還沒有排程的紀錄', 'scheduler 服務啟動後，排程每 '.self::HEARTBEAT_MINUTES.' 分鐘回報一次。超過 '.self::HEARTBEAT_MINUTES.' 分鐘還沒有紀錄的話，'.$advice);
        }
        if ($record['at'] === null) {
            return new StatusCheck(Health::Danger, '排程的紀錄讀不出來（'.self::SCHEDULER_FILE.'）', $advice);
        }

        $now = CarbonImmutable::now();
        $details = ['最近一次回報' => self::when($record['at'], $now)];
        if ($record['at'] < $now->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES)) {
            return new StatusCheck(Health::Danger, '排程已經 '.self::since($record['at'], $now).'沒有回報，scheduler 服務可能停了', $advice.' 排程停了的話，過期的資料不會清除。', $record['at'], $details);
        }

        return new StatusCheck(Health::Ok, '排程正常，最近一次回報是 '.self::ago($record['at'], $now), at: $record['at'], details: $details);
    }

    /**
     * 每天一次的工作（備份、清除）：沒有紀錄、紀錄讀不出來、失敗、太久沒有新的紀錄，或是正常。
     *
     * @param  callable(array<string, mixed>): array<string, string>  $details  從紀錄中取出要顯示的資料
     */
    private function daily(string $file, string $name, string $setup, string $advice, callable $details): StatusCheck
    {
        $record = $this->read($file);
        if ($record === null) {
            return new StatusCheck(Health::Warning, "還沒有{$name}的紀錄", $setup);
        }
        if ($record['at'] === null) {
            return new StatusCheck(Health::Danger, "{$name}的紀錄讀不出來（{$file}）", $advice);
        }

        $now = CarbonImmutable::now();
        $at = $record['at'];
        $when = self::when($at, $now);
        $ok = ($record['ok'] ?? false) === true;
        $error = is_string($record['error'] ?? null) ? $record['error'] : null;

        if (! $ok) {
            return new StatusCheck(Health::Danger, "最近一次{$name}失敗".($error !== null ? '：'.$error : ''), $advice, $at, ['時間' => $when]);
        }

        $shown = ['時間' => $when] + $details($record);
        if ($at < $now->subHours(self::DAILY_MAX_AGE_HOURS)) {
            return new StatusCheck(Health::Warning, '已經 '.self::since($at, $now)."沒有{$name}了，最近一次成功是 {$when}", "{$name}應該每天執行一次。".$advice, $at, $shown);
        }

        return new StatusCheck(Health::Ok, "最近一次{$name}成功：{$when}", at: $at, details: $shown);
    }

    /**
     * 讀一個紀錄檔。沒有檔案時為 null；有檔案但讀不出來時只有 at => null。
     *
     * @return array<string, mixed>|null
     */
    private function read(string $file): ?array
    {
        $disk = $this->disk();
        if (! $disk->exists($file)) {
            return null;
        }

        $record = json_decode((string) $disk->get($file), true);
        if (! is_array($record)) {
            return ['at' => null];
        }

        try {
            $at = is_string($record['at'] ?? null) ? CarbonImmutable::parse($record['at'], 'UTC') : null;
        } catch (Throwable) {
            $at = null;
        }

        return ['at' => $at] + $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $file, array $data): void
    {
        $this->disk()->put($file, json_encode(
            ['at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z')] + $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * storage 目錄所在磁碟的容量（正式環境是 volume）。獨立出來是為了測試時可以換掉。
     *
     * @return array{total: int|float|false, free: int|float|false}
     */
    public function diskSpace(): array
    {
        $root = storage_path();

        return ['total' => @disk_total_space($root), 'free' => @disk_free_space($root)];
    }

    /**
     * public disk 上所有檔案（上傳的媒體與縮圖）的大小與數量。
     *
     * @return array{bytes: int, files: int}
     */
    private function mediaSize(): array
    {
        $disk = Storage::disk('public');
        $bytes = 0;
        $files = $disk->allFiles();
        foreach ($files as $file) {
            $bytes += $disk->size($file);
        }

        return ['bytes' => $bytes, 'files' => count($files)];
    }

    /**
     * SQLite 檔案連同還沒合併回去的 WAL；不是 SQLite 檔案（例如測試的 :memory:）時為 null。
     */
    private function databaseSize(): ?int
    {
        if (config('database.default') !== 'sqlite') {
            return null;
        }
        $path = config('database.connections.sqlite.database');
        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        $bytes = (int) filesize($path);
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($path.$suffix)) {
                $bytes += (int) filesize($path.$suffix);
            }
        }

        return $bytes;
    }

    private static function bytes(int|float $bytes): string
    {
        return Number::fileSize($bytes, precision: 1);
    }

    /**
     * 台灣時間加上距今多久，例：2026-10-06 03:00（3 小時前）。
     */
    private static function when(CarbonImmutable $at, CarbonImmutable $now): string
    {
        return $at->setTimezone((string) config('kancil.timezone'))->format('Y-m-d H:i').'（'.self::ago($at, $now).'）';
    }

    private static function ago(CarbonImmutable $at, CarbonImmutable $now): string
    {
        return $at->diffInMinutes($now, absolute: true) < 1 ? '剛剛' : self::since($at, $now).'前';
    }

    /**
     * 從 $at 到現在經過多久，例：3 小時。
     */
    private static function since(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $minutes = (int) floor($at->diffInMinutes($now, absolute: true));
        if ($minutes < 60) {
            return max($minutes, 1).' 分鐘';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 48) {
            return "{$hours} 小時";
        }

        return intdiv($hours, 24).' 天';
    }
}
