<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Models\Activity;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 老師的成績頁（docs/SPEC.md T-11、3.3、7.4、M2 驗收 4）。
 */
class ActivityResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Set $set;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->set = Set::factory()->for($this->teacher, 'owner')->create();
        $this->writeEntries([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']]);

        $this->activity = Activity::create([
            'set_id' => $this->set->id,
            'game_id' => 'maze-chase',
            'game_version' => '0.1.0',
            'options' => [],
            'owner_id' => $this->teacher->id,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $words
     * @param  list<string>  $keepIds
     */
    private function writeEntries(array $words, array $keepIds = []): void
    {
        app(SetWriter::class)->write($this->set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => array_map(fn ($word, $i) => [
                'id' => $keepIds[$i] ?? null,
                'item' => ['text' => $word[0], 'translation_zh' => $word[1]],
            ], $words, array_keys($words)),
        ], $this->teacher);

        $this->set->refresh();
    }

    /**
     * 學生玩一次：依序送出 [題目, 選了什麼]，最後視需要結束作答。
     *
     * @param  list<array{0: string, 1: string}>  $answers
     */
    private function play(array $answers, bool $complete = true): string
    {
        ['attempt_id' => $attemptId, 'token' => $token] = $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $this->set->current_revision_id,
            'seed' => 1,
            'round_count' => count($this->set->currentRevision->content()->entries),
        ])->assertCreated()->json();

        $presented = $this->set->entries()->pluck('id')->all();
        $this->postJson("/api/v1/attempts/{$attemptId}/responses", [
            'token' => $token,
            'responses' => array_map(fn (array $answer) => [
                'entry_id' => $answer[0],
                'presented' => $presented,
                'selected' => [$answer[1]],
            ], $answers),
        ])->assertOk();

        if ($complete) {
            $this->postJson("/api/v1/attempts/{$attemptId}/complete", ['token' => $token, 'duration_ms' => 42000])->assertOk();
        }

        $this->travel(1)->minute(); // 作答紀錄依開始時間排序

        return $attemptId;
    }

    public function test_only_the_owner_can_see_the_results(): void
    {
        $this->get("/activities/{$this->activity->id}/results")->assertRedirect('/login');
        $this->actingAs(User::factory()->create())
            ->get("/activities/{$this->activity->id}/results")
            ->assertForbidden();
        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results")
            ->assertOk();
    }

    public function test_an_activity_without_attempts_shows_an_empty_summary(): void
    {
        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results")
            ->assertInertia(fn (Assert $page) => $page
                ->component('activities/Results')
                ->where('summary', ['attempts' => 0, 'completed' => 0, 'average_rate' => null])
                ->where('revisions', [])
                ->where('questions', [])
                ->where('attempts.total', 0)
                ->where('detail', null));
    }

    public function test_wrong_rates_count_only_the_first_response_to_each_question(): void
    {
        [$banana, $apple, $orange] = $this->set->entries()->pluck('id')->all();

        // 迷宮答錯後重試答對，仍算答錯（7.4）
        $first = $this->play([[$banana, $apple], [$banana, $banana], [$apple, $apple], [$orange, $orange]]);
        $this->play([[$banana, $apple], [$apple, $banana], [$orange, $orange]]);
        $this->play([[$banana, $banana]], complete: false);

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results?attempt={$first}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('activities/Results')
                ->where('activity.scored', true)
                ->where('summary.attempts', 3)
                ->where('summary.completed', 2)
                ->where('summary.average_rate', 0.5)
                ->has('revisions', 1)
                ->has('questions', 3)
                ->where('questions.0.entry_id', $banana)
                ->where('questions.0.question', ['text' => 'quả chuối', 'note' => '香蕉', 'image' => null, 'audio' => null])
                ->where('questions.0.answer', null)
                ->where('questions.0.answered', 3)
                ->where('questions.0.wrong', 2)
                ->where('questions.0.changed', false)
                ->where('questions.0.common_mistake.count', 2)
                ->where('questions.0.common_mistake.face.text', 'quả táo')
                ->where('questions.1.answered', 2)
                ->where('questions.1.wrong', 1)
                ->where('questions.2.wrong', 0)
                ->where('questions.2.common_mistake', null)
                // 新的作答在前，沒玩完的也列出來
                ->where('attempts.total', 3)
                ->where('attempts.data.0.completed_at', null)
                ->where('attempts.data.2.id', $first)
                ->where('attempts.data.2.correct_count', 2)
                ->where('attempts.data.2.duration_ms', 42000)
                ->where('detail.id', $first)
                ->where('detail.rounds.0.correct', false)
                ->where('detail.rounds.0.tries', 2)
                ->where('detail.rounds.0.selected.text', 'quả táo')
                ->where('detail.rounds.1.correct', true));
    }

    public function test_quiz_sets_show_the_correct_option_and_the_common_mistake(): void
    {
        $set = Set::factory()->quiz()->for($this->teacher, 'owner')->create();
        app(SetWriter::class)->write($set, ['entries' => [[
            'id' => null,
            'question' => [
                'stem' => ['text' => '「謝謝」的越南語是？', 'audio_id' => null, 'image_id' => null],
                'options' => [
                    ['id' => 'a', 'text' => 'cảm ơn', 'image_id' => null, 'correct' => true],
                    ['id' => 'b', 'text' => 'xin chào', 'image_id' => null, 'correct' => false],
                ],
            ],
        ]]], $this->teacher);
        $this->set = $set->refresh();
        $this->activity->update(['set_id' => $set->id, 'game_id' => 'quiz']);

        $entry = $set->entries()->value('id');
        $attempt = $this->play([[$entry, 'b']]);

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results?attempt={$attempt}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('questions.0.question.text', '「謝謝」的越南語是？')
                ->where('questions.0.answer.text', 'cảm ơn')
                ->where('questions.0.common_mistake.face.text', 'xin chào')
                ->where('detail.rounds.0.selected.text', 'xin chào')
                ->where('detail.rounds.0.answer.text', 'cảm ơn'));
    }

    public function test_match_up_on_a_quiz_set_shows_the_card_the_student_placed(): void
    {
        $set = Set::factory()->quiz()->for($this->teacher, 'owner')->create();
        $question = fn (string $stem, string $answer) => [
            'id' => null,
            'question' => [
                'stem' => ['text' => $stem, 'audio_id' => null, 'image_id' => null],
                'options' => [
                    ['id' => 'a', 'text' => $answer, 'image_id' => null, 'correct' => true],
                    ['id' => 'b', 'text' => 'xin chào', 'image_id' => null, 'correct' => false],
                ],
            ],
        ];
        app(SetWriter::class)->write($set, ['entries' => [
            $question('「謝謝」的越南語是？', 'cảm ơn'),
            $question('「再見」的越南語是？', 'tạm biệt'),
        ]], $this->teacher);
        $this->set = $set->refresh();
        $this->activity->update(['set_id' => $set->id, 'game_id' => 'match-up']);

        // 配對時選的是右側卡片，以詞條的 entry ID 識別（7.4）
        [$thanks, $bye] = $set->entries()->pluck('id')->all();
        $attempt = $this->play([[$thanks, $bye], [$thanks, $thanks], [$bye, $bye]]);

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results?attempt={$attempt}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.scored', true)
                ->where('questions.0.wrong', 1)
                ->where('questions.0.common_mistake.face.text', 'tạm biệt')
                ->where('questions.1.wrong', 0)
                ->where('detail.rounds.0.selected.text', 'tạm biệt')
                ->where('detail.rounds.0.answer.text', 'cảm ơn')
                ->where('detail.rounds.0.tries', 2)
                ->where('attempts.data.0.correct_count', 1));
    }

    public function test_flash_cards_record_which_cards_were_viewed(): void
    {
        $this->activity->update(['game_id' => 'flash-cards']);
        [$banana, $apple] = $this->set->entries()->pluck('id')->all();

        ['attempt_id' => $attemptId, 'token' => $token] = $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $this->set->current_revision_id,
            'seed' => 1,
            'round_count' => 3,
        ])->assertCreated()->json();
        $viewed = fn (string $entry) => ['entry_id' => $entry, 'presented' => [], 'selected' => null, 'client_correct' => null, 'duration_ms' => null];
        $this->postJson("/api/v1/attempts/{$attemptId}/responses", [
            'token' => $token,
            'responses' => [$viewed($banana), $viewed($apple)],
        ])->assertOk();
        $this->postJson("/api/v1/attempts/{$attemptId}/complete", ['token' => $token, 'duration_ms' => 30000])
            ->assertOk()
            ->assertJsonPath('correct_count', null);

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results?attempt={$attemptId}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.scored', false)
                ->where('summary.average_rate', null)
                ->where('questions.0.responses', 1)
                ->where('questions.2.responses', 0)
                ->where('detail.rounds.0.answered', true)
                ->where('detail.rounds.0.correct', null)
                ->where('detail.rounds.2.answered', false));
    }

    public function test_old_attempts_are_shown_with_the_revision_they_used(): void
    {
        [$banana, $apple, $orange] = $this->set->entries()->pluck('id')->all();
        $firstRevision = $this->set->current_revision_id;
        $old = $this->play([[$banana, $banana], [$apple, $orange], [$orange, $orange]]);

        // 老師把「柳橙」改成「橘子」，並刪掉蘋果
        $this->writeEntries([['quả chuối', '香蕉'], ['quả cam', '橘子']], [$banana, $orange]);
        $this->play([[$banana, $orange], [$orange, $orange]]);

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results?attempt={$old}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('revisions', 2)
                ->where('revisions.0.number', 2)
                ->where('revisions.0.current', true)
                ->where('revisions.1.id', $firstRevision)
                // 題目以新版本排列與顯示，只在舊版本中的題目排在後面
                ->has('questions', 3)
                ->where('questions.0.entry_id', $banana)
                ->where('questions.0.answered', 2)
                ->where('questions.0.revisions', [2, 1])
                ->where('questions.1.entry_id', $orange)
                ->where('questions.1.question.note', '橘子')
                ->where('questions.1.changed', true)
                ->where('questions.2.entry_id', $apple)
                ->where('questions.2.revisions', [1])
                // 舊的作答仍依當時的版本顯示題目與對錯（M2 驗收 4）
                ->where('attempts.data.1.revision_number', 1)
                ->where('detail.revision_number', 1)
                ->has('detail.rounds', 3)
                ->where('detail.rounds.1.correct', false)
                ->where('detail.rounds.1.selected.note', '柳橙')
                ->where('detail.rounds.2.question.note', '柳橙'));

        // 只看第 1 版
        $this->get("/activities/{$this->activity->id}/results?revision={$firstRevision}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('revision', $firstRevision)
                ->where('summary.attempts', 1)
                ->where('attempts.total', 1)
                ->has('questions', 3)
                ->where('questions.2.question.note', '柳橙')
                ->where('questions.2.changed', false));

        // 不屬於這個活動的版本會被忽略
        $this->get("/activities/{$this->activity->id}/results?revision=01M3ZYPEF8AW2ZE6E3WKCKW0S7")
            ->assertInertia(fn (Assert $page) => $page
                ->where('revision', null)
                ->where('summary.attempts', 2));
    }

    public function test_expanding_an_attempt_only_loads_its_detail(): void
    {
        $banana = $this->set->entries()->value('id');
        $attempt = $this->play([[$banana, $banana]]);

        $this->actingAs($this->teacher);
        $version = $this->get("/activities/{$this->activity->id}/results")->viewData('page')['version'];
        $this->get("/activities/{$this->activity->id}/results?attempt={$attempt}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'activities/Results',
            'X-Inertia-Partial-Data' => 'detail',
        ])
            ->assertOk()
            ->assertJsonPath('props.detail.id', $attempt)
            ->assertJsonMissingPath('props.questions')
            ->assertJsonMissingPath('props.attempts');
    }

    public function test_attempts_from_other_activities_are_not_shown_in_detail(): void
    {
        $other = Activity::create([
            'set_id' => $this->set->id,
            'game_id' => 'quiz',
            'game_version' => '0.1.0',
            'options' => [],
            'owner_id' => $this->teacher->id,
        ]);
        $banana = $this->set->entries()->value('id');
        $this->activity = $other;
        $attempt = $this->play([[$banana, $banana]]);

        $this->actingAs($this->teacher);
        $activity = Activity::where('game_id', 'maze-chase')->firstOrFail();
        $this->get("/activities/{$activity->id}/results?attempt={$attempt}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.attempts', 0)
                ->where('detail', null));
    }
}
