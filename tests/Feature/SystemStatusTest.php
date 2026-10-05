<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Health;
use App\Support\Pruner;
use App\Support\SystemStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * 系統狀態（docs/SPEC.md A-02）：後台只給管理員看的頁面，顯示儲存空間、最近一次備份、
 * 最近一次 kancil:prune 與排程是否在運作（App\Support\SystemStatus）。
 */
class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        // 台灣時間 2026-10-06 10:00
        $this->now = CarbonImmutable::parse('2026-10-06 02:00:00', 'UTC');
        $this->travelTo($this->now);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function record(string $file, array $record): void
    {
        Storage::disk('local')->put($file, json_encode($record, JSON_UNESCAPED_UNICODE));
    }

    private function systemStatus(): SystemStatus
    {
        return app(SystemStatus::class);
    }

    public function test_only_admins_can_see_the_system_status(): void
    {
        $this->actingAs($this->userWithRole('admin'))->get('/admin/system-status')
            ->assertOk()->assertSee('系統狀態')->assertSee('儲存空間');
        $this->get('/admin')->assertSee('system-status');

        // 審核者能進後台，但系統狀態是管理員的事（docs/SPEC.md 4.4）
        $this->actingAs($this->userWithRole('curator'))->get('/admin/system-status')->assertForbidden();
        $this->actingAs($this->userWithRole('teacher'))->get('/admin/system-status')->assertForbidden();

        auth()->logout();
        $this->get('/admin/system-status')->assertRedirect(route('login'));
    }

    public function test_curators_do_not_see_the_system_status_in_the_menu(): void
    {
        // 選單在同一個測試中只會建立一次（Filament 的 NavigationManager），所以另外一個測試
        $this->actingAs($this->userWithRole('curator'))->get('/admin/play-stats')->assertOk()->assertDontSee('system-status');
    }

    public function test_the_page_shows_the_latest_results_and_warnings(): void
    {
        $this->withDiskSpace(['total' => 20 * 1024 ** 3, 'free' => 12 * 1024 ** 3]);
        $this->record(SystemStatus::BACKUP_FILE, [
            'at' => '2026-10-05T19:00:00Z', 'ok' => true, 'file' => '/home/kancil/backups/kancil-20261006-030000.tar.gz', 'bytes' => 1048576, 'kept' => 14,
        ]);
        $this->record(SystemStatus::PRUNE_FILE, ['at' => '2026-10-05T20:00:00Z', 'ok' => false, 'error' => 'disk I/O error']);

        $this->actingAs($this->userWithRole('admin'))->get('/admin/system-status')
            ->assertOk()
            ->assertSee('最近一次備份成功：2026-10-06 03:00（7 小時前）')
            ->assertSee('/home/kancil/backups/kancil-20261006-030000.tar.gz')
            ->assertSee('1.0 MB')
            ->assertSee('最近一次資料的清除失敗：disk I/O error')
            ->assertSee('2026-10-06 04:00（6 小時前）')
            ->assertSee('還沒有排程的紀錄')
            ->assertSee('磁碟空間足夠');
    }

    public function test_a_recent_successful_backup_is_ok(): void
    {
        $this->record(SystemStatus::BACKUP_FILE, [
            'at' => $this->now->subHours(7)->format('Y-m-d\TH:i:s\Z'), 'ok' => true,
            'file' => '/home/kancil/backups/kancil-20261006-030000.tar.gz', 'bytes' => 123456789, 'kept' => 14,
        ]);

        $check = $this->systemStatus()->backup();

        $this->assertSame(Health::Ok, $check->health);
        $this->assertSame('最近一次備份成功：2026-10-06 03:00（7 小時前）', $check->summary);
        $this->assertNull($check->advice);
        $this->assertSame([
            '時間' => '2026-10-06 03:00（7 小時前）',
            '檔案' => '/home/kancil/backups/kancil-20261006-030000.tar.gz',
            '大小' => '117.7 MB',
            '保留的份數' => '14',
        ], $check->details);
    }

    public function test_a_backup_older_than_a_day_is_stale(): void
    {
        $this->record(SystemStatus::BACKUP_FILE, ['at' => $this->now->subHours(31)->format('Y-m-d\TH:i:s\Z'), 'ok' => true]);

        $check = $this->systemStatus()->backup();

        $this->assertSame(Health::Warning, $check->health);
        $this->assertSame('已經 31 小時沒有備份了，最近一次成功是 2026-10-05 03:00（31 小時前）', $check->summary);
        $this->assertStringContainsString('backup.log', (string) $check->advice);

        // 剛好一天多一點還不算
        $this->record(SystemStatus::BACKUP_FILE, ['at' => $this->now->subHours(25)->format('Y-m-d\TH:i:s\Z'), 'ok' => true]);
        $this->assertSame(Health::Ok, $this->systemStatus()->backup()->health);
    }

    public function test_a_failed_backup_is_reported_even_if_recent(): void
    {
        $this->record(SystemStatus::BACKUP_FILE, [
            'at' => $this->now->subMinutes(5)->format('Y-m-d\TH:i:s\Z'), 'ok' => false, 'error' => '備份資料庫與媒體失敗，見主機上的 backup.log',
        ]);

        $check = $this->systemStatus()->backup();

        $this->assertSame(Health::Danger, $check->health);
        $this->assertSame('最近一次備份失敗：備份資料庫與媒體失敗，見主機上的 backup.log', $check->summary);
        $this->assertSame(['時間' => '2026-10-06 09:55（5 分鐘前）'], $check->details);
        $this->assertStringContainsString('docker/backup.sh', (string) $check->advice);
    }

    public function test_missing_and_unreadable_records_are_warnings(): void
    {
        $check = $this->systemStatus()->backup();
        $this->assertSame(Health::Warning, $check->health);
        $this->assertSame('還沒有備份的紀錄', $check->summary);
        $this->assertStringContainsString('crontab', (string) $check->advice);

        Storage::disk('local')->put(SystemStatus::BACKUP_FILE, 'not json');
        $check = $this->systemStatus()->backup();
        $this->assertSame(Health::Danger, $check->health);
        $this->assertSame('備份的紀錄讀不出來（status/backup.json）', $check->summary);

        // 時間格式錯誤也一樣
        $this->record(SystemStatus::PRUNE_FILE, ['at' => 'yesterday-ish', 'ok' => true]);
        $this->assertSame(Health::Danger, $this->systemStatus()->prune()->health);
    }

    public function test_prune_records_its_result(): void
    {
        $this->artisan('kancil:prune --dry-run')->assertSuccessful();
        Storage::disk('local')->assertMissing(SystemStatus::PRUNE_FILE);
        $this->assertSame('還沒有資料的清除的紀錄', $this->systemStatus()->prune()->summary);

        $this->artisan('kancil:prune')->assertSuccessful();

        Storage::disk('local')->assertExists(SystemStatus::PRUNE_FILE);
        $record = json_decode((string) Storage::disk('local')->get(SystemStatus::PRUNE_FILE), true);
        $this->assertSame('2026-10-06T02:00:00Z', $record['at']);
        $this->assertTrue($record['ok']);
        $this->assertSame(['attempts', 'activities', 'sets', 'items', 'set_revisions', 'media', 'files'], array_keys($record['deleted']));

        $check = $this->systemStatus()->prune();
        $this->assertSame(Health::Ok, $check->health);
        $this->assertSame('最近一次資料的清除成功：2026-10-06 10:00（剛剛）', $check->summary);
        $this->assertSame('作答紀錄 0、活動 0、題組 0、詞條 0、題組版本 0、媒體 0、媒體檔案 0', $check->details['刪除的筆數']);

        $this->travelTo($this->now->addHours(27));
        $check = $this->systemStatus()->prune();
        $this->assertSame(Health::Warning, $check->health);
        $this->assertSame('已經 27 小時沒有資料的清除了，最近一次成功是 2026-10-06 10:00（27 小時前）', $check->summary);
        $this->assertStringContainsString('prune.log', (string) $check->advice);
    }

    public function test_a_failed_prune_is_recorded(): void
    {
        $this->mock(Pruner::class)->shouldReceive('run')->andThrow(new RuntimeException('database is locked'));

        $this->artisan('kancil:prune')
            ->expectsOutputToContain('kancil:prune 失敗：database is locked')
            ->assertFailed();

        $check = $this->systemStatus()->prune();
        $this->assertSame(Health::Danger, $check->health);
        $this->assertSame('最近一次資料的清除失敗：database is locked', $check->summary);
        $this->assertSame(['時間' => '2026-10-06 10:00（剛剛）'], $check->details);
        $this->assertStringContainsString('docker compose logs scheduler', (string) $check->advice);
    }

    public function test_the_scheduler_heartbeat_shows_whether_the_scheduler_is_alive(): void
    {
        $check = $this->systemStatus()->scheduler();
        $this->assertSame(Health::Warning, $check->health);
        $this->assertSame('還沒有排程的紀錄', $check->summary);
        $this->assertStringContainsString('docker compose ps', (string) $check->advice);

        $this->systemStatus()->recordHeartbeat();

        $check = $this->systemStatus()->scheduler();
        $this->assertSame(Health::Ok, $check->health);
        $this->assertSame('排程正常，最近一次回報是 剛剛', $check->summary);

        $this->travelTo($this->now->addMinutes(45));
        $check = $this->systemStatus()->scheduler();
        $this->assertSame(Health::Ok, $check->health);
        $this->assertSame('排程正常，最近一次回報是 45 分鐘前', $check->summary);

        $this->travelTo($this->now->addMinutes(61));
        $check = $this->systemStatus()->scheduler();
        $this->assertSame(Health::Danger, $check->health);
        $this->assertSame('排程已經 1 小時沒有回報，scheduler 服務可能停了', $check->summary);
        $this->assertStringContainsString('docker compose ps', (string) $check->advice);
        $this->assertSame(['最近一次回報' => '2026-10-06 10:00（1 小時前）'], $check->details);

        $this->travelTo($this->now->addDays(3));
        $this->assertSame('排程已經 3 天沒有回報，scheduler 服務可能停了', $this->systemStatus()->scheduler()->summary);
    }

    public function test_the_heartbeat_is_scheduled_every_fifteen_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => $event->getSummaryForDisplay() === 'kancil:heartbeat');

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()?->expression);

        $events->first()?->run($this->app);

        Storage::disk('local')->assertExists(SystemStatus::SCHEDULER_FILE);
        $this->assertSame(Health::Ok, $this->systemStatus()->scheduler()->health);
    }

    /**
     * @param  array{total: int, free: int}  $space
     */
    private function withDiskSpace(array $space): SystemStatus
    {
        return $this->partialMock(SystemStatus::class, function ($mock) use ($space) {
            $mock->shouldReceive('diskSpace')->andReturn($space);
        });
    }

    public function test_low_disk_space_is_a_warning(): void
    {
        $gb = 1024 ** 3;

        $check = $this->withDiskSpace(['total' => 20 * $gb, 'free' => 12 * $gb])->storage();
        $this->assertSame(Health::Ok, $check->health);
        $this->assertSame('磁碟空間足夠：剩餘 12.0 GB，共 20.0 GB（剩 60%）', $check->summary);
        $this->assertSame('剩餘 12.0 GB，共 20.0 GB（剩 60%）', $check->details['磁碟']);

        $check = $this->withDiskSpace(['total' => 20 * $gb, 'free' => (int) (1.6 * $gb)])->storage();
        $this->assertSame(Health::Warning, $check->health);
        $this->assertSame('磁碟剩餘空間不多：剩餘 1.6 GB，共 20.0 GB（剩 8%）', $check->summary);

        $check = $this->withDiskSpace(['total' => 20 * $gb, 'free' => (int) (0.5 * $gb)])->storage();
        $this->assertSame(Health::Danger, $check->health);
        $this->assertSame('磁碟快滿了：剩餘 512.0 MB，共 20.0 GB（剩 3%）', $check->summary);
        $this->assertStringContainsString('docker image prune', (string) $check->advice);

        $check = $this->withDiskSpace(['total' => false, 'free' => false])->storage();
        $this->assertSame(Health::Warning, $check->health);
        $this->assertSame('讀不到磁碟空間', $check->summary);
    }

    public function test_storage_reports_media_and_database_sizes(): void
    {
        $status = $this->withDiskSpace(['total' => 20 * 1024 ** 3, 'free' => 12 * 1024 ** 3]);
        Storage::disk('public')->put('media/a.webp', str_repeat('x', 1000));
        Storage::disk('public')->put('media/b.m4a', str_repeat('x', 24));
        $database = tempnam(sys_get_temp_dir(), 'kancil');
        file_put_contents($database, str_repeat('x', 4096));
        file_put_contents($database.'-wal', str_repeat('x', 100));
        config(['database.connections.sqlite.database' => $database]);

        try {
            $check = $status->storage();
        } finally {
            unlink($database);
            unlink($database.'-wal');
        }

        $this->assertSame('1.0 KB（2 個檔案）', $check->details['上傳的媒體']);
        $this->assertSame('4.1 KB（SQLite，含 WAL）', $check->details['資料庫']);

        // 測試用的 :memory: 不是檔案
        config(['database.connections.sqlite.database' => ':memory:']);
        $this->assertSame('—', $status->storage()->details['資料庫']);
    }
}
