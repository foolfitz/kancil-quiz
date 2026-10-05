<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Activity;
use App\Models\Report;
use App\Models\Set;
use App\Models\User;
use App\Notifications\ActivityReported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * 播放頁的檢舉（docs/SPEC.md S-07）：存在後台給管理員處理，並寄信通知管理員。
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $teacher = User::factory()->create(['name' => '林老師']);
        $set = Set::factory()->for($teacher, 'owner')->create(['language_code' => 'id', 'title' => '我的家人']);
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [['item' => ['text' => 'ayah', 'translation_zh' => '爸爸']], ['item' => ['text' => 'ibu', 'translation_zh' => '媽媽']]],
        ], $teacher);
        $this->activity = $set->activities()->create(['game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $teacher->id]);
    }

    private function url(): string
    {
        return "/api/v1/activities/{$this->activity->id}/reports";
    }

    public function test_anyone_can_report_an_activity(): void
    {
        Notification::fake();
        $disabledAdmin = User::factory()->create(['disabled_at' => now()]);
        $disabledAdmin->assignRole('admin');

        $this->postJson($this->url(), ['reason' => '  有廣告連結  '])
            ->assertCreated()
            ->assertJson(['message' => '已經送出，謝謝你。管理員會盡快處理。']);

        $report = Report::sole();
        $this->assertSame('有廣告連結', $report->reason);
        $this->assertNull($report->resolved_at);
        Notification::assertSentTo($this->admin, ActivityReported::class, function (ActivityReported $notification) {
            $mail = $notification->toMail($this->admin);

            return str_contains(implode("\n", $mail->introLines), '活動：我的家人（'.route('play', $this->activity).'）')
                && $mail->actionUrl === url('/admin/reports');
        });
        Notification::assertNotSentTo($disabledAdmin, ActivityReported::class);

        $this->postJson($this->url(), ['reason' => ''])->assertJsonValidationErrors(['reason' => '請寫下這個活動有什麼問題。']);
        $this->postJson('/api/v1/activities/01J0000000000000000000000/reports', ['reason' => '廣告'])->assertNotFound();
    }

    public function test_reports_are_kept_when_the_mail_fails_and_are_rate_limited(): void
    {
        Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP 連不上'));

        for ($i = 0; $i < 10; $i++) {
            $this->postJson($this->url(), ['reason' => '不適合學生'])->assertCreated();
        }
        $this->postJson($this->url(), ['reason' => '不適合學生'])->assertTooManyRequests();
        $this->assertSame(10, Report::count());
    }

    public function test_admins_handle_reports(): void
    {
        $first = $this->activity->reports()->create(['reason' => '有廣告連結', 'created_at' => now()]);
        $second = $this->activity->reports()->create(['reason' => '圖片不適合學生', 'created_at' => now()]);

        $this->actingAs($this->admin);
        $this->assertSame('2', ReportResource::getNavigationBadge());
        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->callTableAction('resolve', $first)
            ->assertCanNotSeeTableRecords([$first]);
        $this->assertSame($this->admin->id, $first->fresh()->resolved_by);

        // 停用老師，同時處理掉這件檢舉
        Livewire::test(ListReports::class)->callTableAction('disableOwner', $second);
        $this->assertTrue($this->activity->owner->fresh()->isDisabled());
        $this->assertNotNull($second->fresh()->resolved_at);
        $this->assertNull(ReportResource::getNavigationBadge());

        $curator = User::factory()->create();
        $curator->assignRole('curator');
        $this->actingAs($curator)->get('/admin/reports')->assertForbidden();
    }
}
