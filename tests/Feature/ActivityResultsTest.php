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
            'game_id' => 'maze-quiz',
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
    private function play(array $answers, bool $complete = true, ?string $label = null): string
    {
        ['attempt_id' => $attemptId, 'token' => $token] = $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $this->set->current_revision_id,
            'seed' => 1,
            'round_count' => count($this->set->currentRevision->content()->entries),
            'player_label' => $label,
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
                ->where('summary', ['attempts' => 0, 'completed' => 0, 'students' => 0, 'unlabelled' => 0, 'average_rate' => null])
                ->where('students', [])
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
        $activity = Activity::where('game_id', 'maze-quiz')->firstOrFail();
        $this->get("/activities/{$activity->id}/results?attempt={$attempt}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.attempts', 0)
                ->where('detail', null));
    }

    public function test_the_owner_can_delete_an_attempt(): void
    {
        $banana = $this->set->entries()->value('id');
        $mine = $this->play([[$banana, $banana]]);
        $student = $this->play([[$banana, $banana]]);
        $url = "/activities/{$this->activity->id}/attempts/{$mine}";

        $this->delete($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->delete($url)->assertForbidden();
        $this->assertDatabaseHas('attempts', ['id' => $mine]);

        // 只能刪除這個活動的作答
        $other = Activity::create(['set_id' => $this->set->id, 'game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->teacher->id]);
        $this->actingAs($this->teacher)->delete("/activities/{$other->id}/attempts/{$mine}")->assertNotFound();

        $this->actingAs($this->teacher)
            ->from("/activities/{$this->activity->id}/results")
            ->delete($url)
            ->assertRedirect("/activities/{$this->activity->id}/results");
        $this->assertDatabaseMissing('attempts', ['id' => $mine]);
        $this->assertDatabaseMissing('attempt_responses', ['attempt_id' => $mine]);
        $this->assertDatabaseHas('attempt_responses', ['attempt_id' => $student]);

        $this->get("/activities/{$this->activity->id}/results")
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.attempts', 1)
                ->where('attempts.data.0.id', $student));
    }

    public function test_students_are_listed_by_name_with_their_first_completed_attempt(): void
    {
        $this->activity->update(['mode' => 'assignment']);
        [$banana, $apple, $orange] = $this->set->entries()->pluck('id')->all();
        $all = fn (string $wrong = '') => [
            [$banana, $wrong === 'banana' ? $apple : $banana],
            [$apple, $apple],
            [$orange, $orange],
        ];

        // 座號 10：第一次沒玩完，第二次 2 題、第三次 3 題
        $this->play([[$banana, $banana]], complete: false, label: '10');
        $counted = $this->play($all('banana'), label: '１０');
        $this->play($all(), label: '10');
        // 座號 2：只玩一次，全對
        $this->play($all(), label: '02');
        // 大小寫不同視為同一位，以第一次的寫法顯示
        $this->play($all('banana'), label: 'Andi');
        $this->play($all(), label: 'andi');

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results")
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.require_label', true)
                ->where('summary.attempts', 6)
                ->where('summary.students', 3)
                ->where('summary.unlabelled', 0)
                // 每位學生只算計分的那一次：(2 + 3 + 2) / 9
                ->where('summary.average_rate', round(7 / 9, 4))
                ->has('students', 3)
                // 數字依數值排序
                ->where('students.0.label', '2')
                ->where('students.1.label', '10')
                ->where('students.1.counted.id', $counted)
                ->where('students.1.counted.correct_count', 2)
                ->where('students.1.best', ['correct_count' => 3, 'round_count' => 3])
                ->where('students.1.completed', 2)
                ->has('students.1.attempts', 3)
                ->where('students.1.attempts.0.counted', false)
                ->where('students.1.attempts.1.counted', true)
                ->where('students.2.label', 'Andi')
                ->has('students.2.attempts', 2)
                // 「香蕉」只有座號 10 與 Andi 的計分作答答錯，重玩答對的不算
                ->where('questions.0.entry_id', $banana)
                ->where('questions.0.answered', 3)
                ->where('questions.0.wrong', 2));
    }

    public function test_attempts_without_a_name_are_each_counted(): void
    {
        [$banana, $apple, $orange] = $this->set->entries()->pluck('id')->all();

        // 開啟「要輸入名字」之前的兩次不記名作答
        $this->play([[$banana, $apple], [$apple, $apple], [$orange, $orange]]);
        $this->play([[$banana, $apple], [$apple, $apple], [$orange, $orange]]);
        $this->activity->update(['mode' => 'assignment']);
        $this->play([[$banana, $banana], [$apple, $apple], [$orange, $orange]], label: '7');
        $this->play([[$banana, $apple], [$apple, $apple], [$orange, $orange]], label: '7');

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results")
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.students', 1)
                ->where('summary.unlabelled', 2)
                ->where('questions.0.answered', 3)
                ->where('questions.0.wrong', 2)
                ->has('students', 1));
    }

    public function test_practice_activities_have_no_student_list(): void
    {
        [$banana] = $this->set->entries()->pluck('id')->all();
        $this->play([[$banana, $banana]]);

        $this->actingAs($this->teacher)
            ->get("/activities/{$this->activity->id}/results")
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.require_label', false)
                ->where('summary.unlabelled', 1)
                ->where('students', []));
    }

    public function test_student_results_can_be_downloaded_as_csv(): void
    {
        $this->activity->update(['mode' => 'assignment']);
        [$banana, $apple, $orange] = $this->set->entries()->pluck('id')->all();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 01:15:00', 'UTC'));

        $this->play([[$banana, $apple], [$apple, $apple], [$orange, $orange]], label: '3');
        $this->play([[$banana, $banana], [$apple, $apple], [$orange, $orange]], label: '3');
        $this->play([[$banana, $banana]], complete: false, label: '=HYPERLINK("a.tw")');

        $this->get("/activities/{$this->activity->id}/results.csv")->assertRedirect('/login');
        $this->actingAs(User::factory()->create())
            ->get("/activities/{$this->activity->id}/results.csv")
            ->assertForbidden();

        $response = $this->actingAs($this->teacher)->get("/activities/{$this->activity->id}/results.csv")->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.csv', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $lines = array_map(str_getcsv(...), explode("\n", trim(substr($csv, 3))));
        $this->assertSame(['名字或座號', '答對（第一次玩完）', '題數', '最高答對', '玩了幾次', '玩完幾次', '最後作答時間'], $lines[0]);
        // 開頭是 = 的名字加上 '，試算表不會當成公式
        $this->assertSame(['3', '2', '3', '3', '2', '2', '2026-10-06 09:16'], $lines[1]);
        $this->assertSame(["'=HYPERLINK(\"a.tw\")", '', '3', '', '1', '0', '2026-10-06 09:17'], $lines[2]);
    }
}
