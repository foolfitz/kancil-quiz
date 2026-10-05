<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Curriculum\PlayCounts;
use App\Filament\Pages\PlayStats;
use App\Models\Activity;
use App\Models\CurriculumPlay;
use App\Models\CurriculumRef;
use App\Models\Language;
use App\Models\Set;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 教材的人氣統計（docs/SPEC.md S-06、A-04）：試玩只累計每課、每個遊戲、每天的次數，後台另外列出課堂的作答數。
 */
class CurriculumPlayTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private CurriculumRef $ref;

    private string $url = '/api/v1/curriculum/id/1/3/plays';

    protected function setUp(): void
    {
        parent::setUp();

        // 台灣時間 2026-10-05 20:00
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
        $this->teacher = User::factory()->create();
        $this->ref = CurriculumRef::create([
            'language_code' => 'id', 'volume' => 1, 'lesson' => 3,
            'title_zh' => '我的家人', 'title_native' => 'Keluarga Saya',
            'set_id' => $this->vocabSet()->id,
        ]);
    }

    private function vocabSet(): Set
    {
        $set = Set::factory()->for($this->teacher, 'owner')->create(['language_code' => 'id', 'title' => '第 1 冊第 3 課']);
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [
                ['item' => ['text' => 'ayah', 'translation_zh' => '爸爸']],
                ['item' => ['text' => 'ibu', 'translation_zh' => '媽媽']],
            ],
        ], $this->teacher);

        return $set->refresh();
    }

    private function activity(Set $set, string $game): Activity
    {
        return $set->activities()->create([
            'game_id' => $game, 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->teacher->id,
        ]);
    }

    private function attempt(Activity $activity, bool $completed): void
    {
        $activity->attempts()->create([
            'set_revision_id' => $activity->set->current_revision_id,
            'seed' => 1,
            'round_count' => 2,
            'token_hash' => str_repeat('0', 64),
            'started_at' => now(),
            'completed_at' => $completed ? now() : null,
        ]);
    }

    /**
     * @return array<string, array{int, int}> 「遊戲 日期」對應開始與玩完的次數
     */
    private function counts(): array
    {
        return CurriculumPlay::orderBy('id')->get()
            ->mapWithKeys(fn (CurriculumPlay $play) => ["{$play->game_id} {$play->date->toDateString()}" => [$play->starts, $play->finishes]])
            ->all();
    }

    public function test_trial_plays_are_counted_per_lesson_game_and_taiwan_day(): void
    {
        $response = $this->postJson($this->url, ['game' => 'quiz', 'event' => 'start'])->assertNoContent();
        // 不設 cookie
        $this->assertSame([], $response->headers->getCookies());
        $this->postJson($this->url, ['game' => 'quiz', 'event' => 'start'])->assertNoContent();
        $this->postJson($this->url, ['game' => 'quiz', 'event' => 'finish'])->assertNoContent();
        $this->postJson($this->url, ['game' => 'flash-cards', 'event' => 'start'])->assertNoContent();

        // 台灣時間過了午夜就是新的一天
        $this->travelTo(CarbonImmutable::parse('2026-10-05 16:30:00', 'UTC'));
        $this->postJson($this->url, ['game' => 'quiz', 'event' => 'finish'])->assertNoContent();

        $this->assertSame([
            'quiz 2026-10-05' => [2, 1],
            'flash-cards 2026-10-05' => [1, 0],
            'quiz 2026-10-06' => [0, 1],
        ], $this->counts());
    }

    public function test_counts_need_a_known_game_event_and_playable_lesson(): void
    {
        $this->postJson($this->url, ['game' => 'no-such-game', 'event' => 'start'])->assertUnprocessable();
        $this->postJson($this->url, ['game' => 'quiz', 'event' => 'answer'])->assertUnprocessable();
        $this->postJson('/api/v1/curriculum/id/1/9/plays', ['game' => 'quiz', 'event' => 'start'])->assertNotFound();

        // 有課名、還沒匯入詞彙的課不能玩，也就不能計次
        CurriculumRef::create(['language_code' => 'id', 'volume' => 2, 'lesson' => 1, 'title_zh' => '我的學校']);
        $this->postJson('/api/v1/curriculum/id/2/1/plays', ['game' => 'quiz', 'event' => 'start'])->assertNotFound();

        Language::whereKey('id')->update(['enabled' => false]);
        $this->postJson($this->url, ['game' => 'quiz', 'event' => 'start'])->assertNotFound();

        $this->assertSame(0, CurriculumPlay::count());
    }

    public function test_the_trial_page_tells_the_player_where_to_count(): void
    {
        $this->get('/curriculum/id/1/3/play/quiz')->assertOk()
            ->assertSee('data-trial="1"', false)
            ->assertSee('data-plays-url="'.$this->url.'"', false);

        // 學生的活動與老師的預覽不計次
        $activity = $this->activity($this->ref->set()->firstOrFail(), 'quiz');
        $this->get("/p/{$activity->id}")->assertOk()->assertDontSee('data-plays-url', false);
    }

    public function test_the_summary_separates_trial_and_classroom_plays(): void
    {
        PlayCounts::record($this->ref, 'quiz', 'start');
        PlayCounts::record($this->ref, 'quiz', 'start');
        PlayCounts::record($this->ref, 'quiz', 'finish');
        $textbook = $this->ref->set()->firstOrFail();
        $activity = $this->activity($textbook, 'quiz');
        $this->attempt($activity, completed: true);
        $this->attempt($activity, completed: false);
        $this->attempt($this->activity($textbook, 'match-up'), completed: true);
        // 老師自己的題組，即使對應這一課也不算
        $copy = $this->vocabSet();
        $copy->curriculumRefs()->attach($this->ref);
        $this->attempt($this->activity($copy, 'quiz'), completed: true);

        // 40 天前
        $this->travelTo(CarbonImmutable::parse('2026-08-26 12:00:00', 'UTC'));
        PlayCounts::record($this->ref, 'quiz', 'start');
        $this->attempt($activity, completed: true);
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));

        $summary = app(PlayCounts::class)->summary(30);
        $key = "{$this->ref->id}:quiz";
        $this->assertSame(['trial_starts' => 2, 'trial_finishes' => 1, 'class_starts' => 2, 'class_finishes' => 1], array_intersect_key($summary[$key], array_flip(['trial_starts', 'trial_finishes', 'class_starts', 'class_finishes'])));
        $this->assertSame('第 1 冊第 3 課：Keluarga Saya 我的家人', $summary[$key]['title']);
        $this->assertSame('印尼語', $summary[$key]['language']);
        $this->assertSame('選擇題', $summary[$key]['game']);
        $this->assertSame([0, 0, 1, 1], array_values(array_intersect_key($summary["{$this->ref->id}:match-up"], array_flip(['trial_starts', 'trial_finishes', 'class_starts', 'class_finishes']))));

        $all = app(PlayCounts::class)->summary();
        $this->assertSame(3, $all[$key]['trial_starts']);
        $this->assertSame(3, $all[$key]['class_starts']);

        $this->assertSame([], app(PlayCounts::class)->summary(null, 'vi')->all());
    }

    public function test_admins_and_curators_see_the_popularity_page(): void
    {
        PlayCounts::record($this->ref, 'flash-cards', 'start');

        foreach (['admin', 'curator'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->get('/admin/play-stats')->assertOk()
                ->assertSee('人氣統計')
                ->assertSee('第 1 冊第 3 課：Keluarga Saya 我的家人')
                ->assertSee('字卡');
        }

        // 預設是最近 30 天，清掉就是全部
        PlayCounts::record(CurriculumRef::create(['language_code' => 'id', 'volume' => 1, 'lesson' => 4, 'title_native' => 'Selamat Pagi, Kakek', 'title_zh' => '爺爺早安', 'set_id' => $this->vocabSet()->id]), 'quiz', 'start');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'UTC'));
        PlayCounts::record($this->ref, 'match-up', 'start');
        Livewire::test(PlayStats::class)
            ->assertSee('配對')
            ->assertDontSee('Selamat Pagi, Kakek')
            ->removeTableFilter('period')
            ->assertSee('Selamat Pagi, Kakek')
            ->filterTable('language', 'vi')
            ->assertSee('這段期間還沒有人玩教材');

        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $this->actingAs($teacher)->get('/admin/play-stats')->assertForbidden();
    }
}
