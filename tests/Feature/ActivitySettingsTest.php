<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Models\Activity;
use App\Models\Set;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 活動的設定（docs/SPEC.md 3.4、T-14）：學生要不要輸入名字或座號，以及開放與截止時間。
 * 老師端以台灣時間輸入與顯示，資料庫存 UTC。
 */
class ActivitySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Set $set;

    protected function setUp(): void
    {
        parent::setUp();

        // 台灣時間 2026-10-04（週日）14:30
        $this->travelTo(CarbonImmutable::parse('2026-10-04 06:30:00', 'UTC'));

        $this->teacher = User::factory()->create();
        $this->set = Set::factory()->for($this->teacher, 'owner')->create();
        app(SetWriter::class)->write($this->set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => array_map(fn (array $word) => [
                'item' => ['text' => $word[0], 'translation_zh' => $word[1]],
            ], [['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']]),
        ], $this->teacher);
        $this->actingAs($this->teacher);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function create(array $settings): Activity
    {
        $this->post("/sets/{$this->set->id}/activities", ['game_id' => 'quiz', ...$settings])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Activity::latest('id')->firstOrFail();
    }

    public function test_new_activities_default_to_one_week_from_today(): void
    {
        $this->get("/sets/{$this->set->id}/activities/create")->assertInertia(fn (Assert $page) => $page
            ->component('activities/Create')
            ->where('settings', [
                'require_label' => false,
                'opens_at' => '2026-10-04T00:00',
                'closes_at' => '2026-10-10T23:59',
            ]));
    }

    public function test_times_are_entered_in_taiwan_time_and_stored_in_utc(): void
    {
        $activity = $this->create([
            'require_label' => true,
            'opens_at' => '2026-10-06T08:00',
            'closes_at' => '2026-10-10T23:59',
        ]);

        $this->assertSame('assignment', $activity->mode);
        $this->assertSame('2026-10-06 00:00:00', $activity->opens_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10 15:59:00', $activity->closes_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $activity->status());

        $this->get("/activities/{$activity->id}")->assertInertia(fn (Assert $page) => $page
            ->where('activity.settings', [
                'require_label' => true,
                'opens_at' => '2026-10-06T08:00',
                'closes_at' => '2026-10-10T23:59',
                'status' => 'scheduled',
            ])
            ->where('defaults.closes_at', '2026-10-10T23:59'));

        // 學生端拿到的是 UTC 的 ISO 8601
        $this->getJson("/api/v1/activities/{$activity->id}")
            ->assertJsonPath('mode', 'assignment')
            ->assertJsonPath('opens_at', '2026-10-06T00:00:00+00:00');
    }

    public function test_both_settings_are_optional(): void
    {
        $activity = $this->create(['opens_at' => null, 'closes_at' => null]);

        $this->assertSame('practice', $activity->mode);
        $this->assertNull($activity->opens_at);
        $this->assertNull($activity->closes_at);
        $this->assertSame('open', $activity->status());

        // 只設截止時間：立刻開放
        $this->assertSame('open', $this->create(['closes_at' => '2026-10-04T15:00'])->status());
    }

    public function test_invalid_times_are_rejected(): void
    {
        $post = fn (array $settings) => $this->post("/sets/{$this->set->id}/activities", ['game_id' => 'quiz', ...$settings]);

        $post(['opens_at' => '2026-10-08T08:00', 'closes_at' => '2026-10-08T08:00'])
            ->assertSessionHasErrors(['closes_at' => '截止時間要晚於開放時間。']);
        // 現在是台灣時間 14:30
        $post(['closes_at' => '2026-10-04T14:00'])->assertSessionHasErrors('closes_at');
        $post(['opens_at' => 'next monday'])->assertSessionHasErrors('opens_at');
        $post(['closes_at' => '2026/10/10 23:59'])->assertSessionHasErrors('closes_at');
        $post(['require_label' => 'maybe'])->assertSessionHasErrors('require_label');

        $this->assertSame(0, Activity::count());
    }

    public function test_settings_can_be_changed_after_creation(): void
    {
        $activity = $this->create(['closes_at' => '2026-10-10T23:59']);

        // 延後截止並要求輸入名字
        $this->patch("/activities/{$activity->id}", ['require_label' => true, 'closes_at' => '2026-10-17T23:59'])
            ->assertSessionHasNoErrors();
        $activity->refresh();
        $this->assertTrue($activity->requiresLabel());
        $this->assertSame('2026-10-17T23:59', $activity->closes_at?->setTimezone('Asia/Taipei')->format('Y-m-d\TH:i'));

        // 沒送的欄位不變；送 null 表示清除
        $this->patch("/activities/{$activity->id}", ['closes_at' => null])->assertSessionHasNoErrors();
        $activity->refresh();
        $this->assertTrue($activity->requiresLabel());
        $this->assertNull($activity->closes_at);

        $this->actingAs(User::factory()->create())
            ->patch("/activities/{$activity->id}", ['require_label' => false])
            ->assertForbidden();
    }

    public function test_closing_now_stops_new_attempts(): void
    {
        $activity = $this->create(['closes_at' => '2026-10-10T23:59']);

        $this->post("/activities/{$activity->id}/close")->assertRedirect();
        $activity->refresh();
        $this->assertSame('closed', $activity->status());

        $this->postJson("/api/v1/activities/{$activity->id}/attempts", [
            'set_revision_id' => $this->set->fresh()?->current_revision_id,
            'seed' => 1,
            'round_count' => 3,
        ])->assertForbidden()->assertJsonPath('message', '這個活動已經截止');

        // 截止後可以再延後，重新開放
        $this->patch("/activities/{$activity->id}", ['closes_at' => '2026-10-11T12:00'])->assertSessionHasNoErrors();
        $this->assertSame('open', $activity->fresh()?->status());

        $this->actingAs(User::factory()->create())->post("/activities/{$activity->id}/close")->assertForbidden();
    }

    public function test_the_dashboard_shows_deadlines(): void
    {
        $this->create(['require_label' => true, 'closes_at' => '2026-10-10T23:59']);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.settings.require_label', true)
            ->where('activities.0.settings.closes_at', '2026-10-10T23:59')
            ->where('activities.0.settings.status', 'open'));
    }

    public function test_previews_can_show_the_name_field(): void
    {
        $html = (string) $this->get("/sets/{$this->set->id}/activities/preview?game=quiz&require_label=1")
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('#<script type="application/json" id="kq-playback">(.+?)</script>#s', $html, $match));
        $this->assertSame('assignment', json_decode($match[1], flags: JSON_THROW_ON_ERROR)->mode);
    }
}
