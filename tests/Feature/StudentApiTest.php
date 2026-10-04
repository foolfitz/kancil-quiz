<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Media;
use App\Models\Set;
use App\Models\User;
use App\Support\KancilFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Set $set;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['name' => '王老師']);
        $this->set = Set::factory()->for($this->teacher, 'owner')->create();
        $this->writeEntries([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']]);

        $this->activity = Activity::create([
            'set_id' => $this->set->id,
            'game_id' => 'quiz',
            'game_version' => '0.1.0',
            'options' => [],
            'owner_id' => $this->teacher->id,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $words
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
     * @return list<string>
     */
    private function entryIds(): array
    {
        return $this->set->entries()->pluck('id')->all();
    }

    private function start(): array
    {
        return $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $this->set->current_revision_id,
            'seed' => 42,
            'round_count' => 3,
        ])->assertCreated()->json();
    }

    public function test_activity_payload_follows_the_activity_format(): void
    {
        $response = $this->getJson("/api/v1/activities/{$this->activity->id}")->assertOk();

        $payload = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], app(KancilFormat::class)->activityErrors($payload));
        $this->assertSame($this->set->current_revision_id, $payload->set_revision_id);
        $this->assertSame('quả chuối', $payload->set->entries[0]->item->text);
        $this->assertSame([['name' => '王老師']], $response->json('set.authors'));
    }

    public function test_media_paths_become_absolute_urls(): void
    {
        $media = Media::create([
            'kind' => 'image', 'path' => 'media/banana.webp', 'mime' => 'image/webp', 'bytes' => 10,
            'uploaded_by' => $this->teacher->id,
        ]);
        app(SetWriter::class)->write($this->set, [
            'faces' => ['prompt' => ['image'], 'answer' => ['text']],
            'entries' => [['id' => $this->entryIds()[0], 'item' => ['text' => 'quả chuối', 'translation_zh' => '香蕉', 'image_id' => $media->id]]],
        ], $this->teacher);

        $this->set->refresh();
        $this->assertStringContainsString("media/{$media->id}.webp", $this->set->currentRevision->getAttribute('content'));

        $this->getJson("/api/v1/activities/{$this->activity->id}")
            ->assertJsonPath('set.entries.0.item.image.src', $media->url());
    }

    public function test_a_full_attempt_is_graded_by_the_server(): void
    {
        ['attempt_id' => $attemptId, 'token' => $token] = $this->start();
        [$banana, $apple, $orange] = $this->entryIds();

        $this->postJson("/api/v1/attempts/{$attemptId}/responses", [
            'token' => $token,
            'responses' => [
                // 第一題答錯後重試答對，仍算答錯
                ['entry_id' => $banana, 'presented' => [$banana, $apple, $orange], 'selected' => [$apple], 'client_correct' => false, 'duration_ms' => 1500],
                ['entry_id' => $banana, 'presented' => [$banana, $apple, $orange], 'selected' => [$banana], 'client_correct' => true, 'duration_ms' => 900],
                // 遊戲回報答錯，但伺服器判定為答對：以伺服器為準
                ['entry_id' => $apple, 'presented' => [$banana, $apple, $orange], 'selected' => [$apple], 'client_correct' => false],
            ],
        ])->assertOk()->assertJson(['accepted' => 3]);

        $this->postJson("/api/v1/attempts/{$attemptId}/complete", [
            'token' => $token,
            'game_score' => 9999,
            'duration_ms' => 30000,
        ])->assertOk()->assertJson([
            'correct_count' => 1,
            'round_count' => 3,
            'results' => [
                ['entry_id' => $banana, 'correct' => false],
                ['entry_id' => $apple, 'correct' => true],
            ],
        ]);

        $this->assertDatabaseHas('attempts', ['id' => $attemptId, 'correct_count' => 1, 'game_score' => 9999]);
        $this->assertDatabaseHas('attempt_responses', ['entry_id' => $apple, 'correct' => true, 'client_correct' => false]);
    }

    public function test_requests_with_a_wrong_token_are_rejected(): void
    {
        ['attempt_id' => $attemptId] = $this->start();

        $this->postJson("/api/v1/attempts/{$attemptId}/complete", ['token' => 'wrong'])->assertForbidden();
    }

    public function test_responses_for_unknown_entries_are_rejected(): void
    {
        ['attempt_id' => $attemptId, 'token' => $token] = $this->start();

        $this->postJson("/api/v1/attempts/{$attemptId}/responses", [
            'token' => $token,
            'responses' => [['entry_id' => '01M3ZYPEF8AW2ZE6E3WKCKW0S7', 'presented' => [], 'selected' => ['x']]],
        ])->assertUnprocessable();
    }

    public function test_no_responses_after_completion(): void
    {
        ['attempt_id' => $attemptId, 'token' => $token] = $this->start();
        $this->postJson("/api/v1/attempts/{$attemptId}/complete", ['token' => $token])->assertOk();

        $entry = $this->entryIds()[0];
        $this->postJson("/api/v1/attempts/{$attemptId}/responses", [
            'token' => $token,
            'responses' => [['entry_id' => $entry, 'presented' => [$entry], 'selected' => [$entry]]],
        ])->assertStatus(409);
    }

    public function test_the_revision_must_belong_to_the_activity_set(): void
    {
        $other = Set::factory()->for($this->teacher, 'owner')->create();
        app(SetWriter::class)->write($other, ['faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']], 'entries' => []], $this->teacher);

        $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $other->fresh()->current_revision_id,
            'seed' => 1,
            'round_count' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('set_revision_id');
    }

    public function test_editing_the_set_creates_a_new_revision_and_old_attempts_keep_theirs(): void
    {
        ['attempt_id' => $attemptId, 'token' => $token] = $this->start();
        $firstRevision = $this->set->current_revision_id;
        [$banana, $apple, $orange] = $this->entryIds();

        // 老師修正錯字：活動立即使用新版本
        $this->writeEntries([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '橘子']], [$banana, $apple, $orange]);
        $this->assertNotSame($firstRevision, $this->set->current_revision_id);
        $this->assertSame(2, $this->set->currentRevision->number);
        $this->getJson("/api/v1/activities/{$this->activity->id}")
            ->assertJsonPath('set_revision_id', $this->set->current_revision_id);

        // 進行中的作答仍以開始時的版本判定
        $this->postJson("/api/v1/attempts/{$attemptId}/responses", [
            'token' => $token,
            'responses' => [['entry_id' => $orange, 'presented' => [$orange, $apple], 'selected' => [$orange]]],
        ])->assertOk();
        $this->assertDatabaseHas('attempts', ['id' => $attemptId, 'set_revision_id' => $firstRevision]);
    }

    public function test_saving_unchanged_content_does_not_create_a_revision(): void
    {
        $revision = $this->set->current_revision_id;
        $this->writeEntries([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']], $this->entryIds());

        $this->assertSame($revision, $this->set->current_revision_id);
    }

    public function test_closed_activities_cannot_be_started(): void
    {
        $this->activity->update(['closes_at' => now()->subMinute()]);

        $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $this->set->current_revision_id,
            'seed' => 1,
            'round_count' => 1,
        ])->assertForbidden()->assertJsonPath('message', '這個活動已經截止');

        $this->activity->update(['opens_at' => now()->addDay(), 'closes_at' => null]);

        $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $this->set->current_revision_id,
            'seed' => 1,
            'round_count' => 1,
        ])->assertForbidden()->assertJsonPath('message', '這個活動還沒開放');
    }

    public function test_attempts_started_before_the_deadline_can_be_finished_after_it(): void
    {
        $this->activity->update(['closes_at' => now()->addMinutes(2)]);
        ['attempt_id' => $attemptId, 'token' => $token] = $this->start();

        $this->travel(5)->minutes();
        $this->assertSame('closed', $this->activity->fresh()?->status());

        $this->postJson("/api/v1/attempts/{$attemptId}/responses", [
            'token' => $token,
            'responses' => [['entry_id' => $this->entryIds()[0], 'presented' => $this->entryIds(), 'selected' => [$this->entryIds()[0]]]],
        ])->assertOk();
        $this->postJson("/api/v1/attempts/{$attemptId}/complete", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('correct_count', 1);
    }

    public function test_activities_that_require_a_name_reject_attempts_without_one(): void
    {
        $this->activity->update(['mode' => 'assignment']);
        $start = fn (mixed $label) => $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
            'set_revision_id' => $this->set->current_revision_id,
            'seed' => 1,
            'round_count' => 3,
            'player_label' => $label,
        ]);

        $start(null)->assertUnprocessable()->assertJsonPath('message', '請填寫名字或座號。');
        $start('   ')->assertUnprocessable();
        $start(str_repeat('王', 21))->assertUnprocessable()->assertJsonPath('message', '名字或座號 最多 20 個字。');
        $start(str_repeat('王', 20))->assertCreated();
    }

    public function test_names_are_normalized_so_the_same_student_matches(): void
    {
        $this->activity->update(['mode' => 'assignment']);
        $label = function (string $label): ?string {
            $id = $this->postJson("/api/v1/activities/{$this->activity->id}/attempts", [
                'set_revision_id' => $this->set->current_revision_id,
                'seed' => 1,
                'round_count' => 3,
                'player_label' => $label,
            ])->assertCreated()->json('attempt_id');

            return Attempt::findOrFail($id)->player_label;
        };

        // 全形轉半形、合併空白、數字去掉前導的 0
        $this->assertSame('5', $label('０５'));
        $this->assertSame('5', $label(' 05 '));
        $this->assertSame('0', $label('00'));
        $this->assertSame('3年2班 7', $label('3年2班　　７'));
        $this->assertSame('Andi', $label('Ａｎｄｉ'));
        // 越南文以 NFC 儲存
        $this->assertSame("Nguy\u{1EC5}n", $label("Nguye\u{0302}\u{0303}n"));
    }

    public function test_practice_activities_do_not_need_a_name(): void
    {
        $this->start();
        $this->assertDatabaseHas('attempts', ['activity_id' => $this->activity->id, 'player_label' => null]);
    }
}
