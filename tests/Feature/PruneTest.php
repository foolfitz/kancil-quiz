<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\AttemptResponse;
use App\Models\CurriculumRef;
use App\Models\Item;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\SetReview;
use App\Models\SetRevision;
use App\Models\User;
use App\Support\Pruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 資料的保存期限（docs/SPEC.md 第 5、9 節）：作答紀錄、老師刪除的資料、舊的題組版本與沒有引用的媒體，
 * 由排程每天執行的 kancil:prune 清除（App\Support\Pruner）。
 */
class PruneTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->now = CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC');
        $this->travelTo($this->now);
        $this->teacher = User::factory()->create();
    }

    private function at(string $ago): void
    {
        $this->travelTo($this->now->sub($ago));
    }

    private function media(string $kind = 'image'): Media
    {
        $path = 'media/'.Str::random(32).($kind === 'image' ? '.webp' : '.m4a');
        $thumbnail = $kind === 'image' ? 'media/'.Str::random(32).'.webp' : null;
        Storage::disk('public')->put($path, 'x');
        if ($thumbnail !== null) {
            Storage::disk('public')->put($thumbnail, 'x');
        }

        return Media::create([
            'kind' => $kind, 'path' => $path, 'thumbnail_path' => $thumbnail,
            'mime' => $kind === 'image' ? 'image/webp' : 'audio/mp4', 'bytes' => 1,
            'uploaded_by' => $this->teacher->id,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string|null}>  $words  詞、中文意思、插圖的媒體 ID
     */
    private function writeVocab(Set $set, array $words): void
    {
        $ids = $set->entries()->orderBy('position')->pluck('id')->all();
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => array_map(fn (array $word, int $i) => [
                'id' => $ids[$i] ?? null,
                'item' => ['text' => $word[0], 'translation_zh' => $word[1], 'image_id' => $word[2] ?? null],
            ], $words, array_keys($words)),
        ], $this->teacher);
        $set->refresh();
    }

    private function vocabSet(): Set
    {
        $set = Set::factory()->for($this->teacher, 'owner')->create();
        $this->writeVocab($set, [['quả chuối', '香蕉'], ['quả táo', '蘋果']]);

        return $set;
    }

    private function activity(Set $set): Activity
    {
        return $set->activities()->create([
            'game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $set->owner_id,
        ]);
    }

    private function attempt(Activity $activity, ?string $revisionId = null): Attempt
    {
        $attempt = $activity->attempts()->create([
            'set_revision_id' => $revisionId ?? $activity->set->current_revision_id,
            'seed' => 1,
            'round_count' => 1,
            'token_hash' => str_repeat('0', 64),
            'started_at' => now(),
        ]);
        $attempt->responses()->create([
            'entry_id' => (string) Str::ulid(),
            'presented' => [],
            'selected' => ['text' => 'quả chuối'],
            'correct' => true,
        ]);

        return $attempt;
    }

    /**
     * @return array<string, int>
     */
    private function prune(bool $dryRun = false): array
    {
        $this->travelTo($this->now);

        return app(Pruner::class)->run($dryRun);
    }

    public function test_attempts_are_kept_for_twelve_months(): void
    {
        $activity = $this->activity($this->vocabSet());
        $this->at('13 months');
        $old = $this->attempt($activity);
        $this->at('11 months');
        $recent = $this->attempt($activity);

        $result = $this->prune();

        $this->assertSame(1, $result['attempts']);
        $this->assertModelMissing($old);
        $this->assertSame(0, AttemptResponse::where('attempt_id', $old->id)->count());
        $this->assertModelExists($recent);
        $this->assertSame(1, $recent->responses()->count());
    }

    public function test_the_attempt_retention_is_configurable(): void
    {
        config(['kancil.retention.attempt_months' => 6]);
        $activity = $this->activity($this->vocabSet());
        $this->at('7 months');
        $attempt = $this->attempt($activity);

        $this->prune();

        $this->assertModelMissing($attempt);
    }

    public function test_old_revisions_are_pruned_unless_attempts_or_reviews_refer_to_them(): void
    {
        $set = Set::factory()->for($this->teacher, 'owner')->create();
        $words = [['quả chuối', '香蕉']];
        foreach (['90 days', '80 days', '70 days', '60 days', '40 days', '10 days'] as $i => $ago) {
            $this->at($ago);
            $words[] = ["từ {$i}", "詞 {$i}"];
            $this->writeVocab($set, $words);
            if ($i === 0) {
                // 第 1 版有作答紀錄
                $this->attempt($this->activity($set));
            }
            if ($i === 2) {
                // 第 3 版申請過公開
                SetReview::create(['set_id' => $set->id, 'set_revision_id' => $set->current_revision_id, 'user_id' => $this->teacher->id, 'action' => 'requested']);
            }
        }

        $result = $this->prune();

        // 第 2 版、第 4 版被取代超過 30 天，也沒有引用；第 5 版在 10 天前才被取代
        $this->assertSame(2, $result['set_revisions']);
        $this->assertSame([1, 3, 5, 6], $set->revisions()->orderBy('number')->pluck('number')->all());

        $this->actingAs($this->teacher)->get("/sets/{$set->id}")->assertInertia(fn (Assert $page) => $page
            ->where('revisions.0.number', 6)
            ->where('revisions.0.changes.0.kind', 'added')
            ->where('revisions.1.number', 5)
            ->where('revisions.1.changes.0.label', '第 4 版已清除，以下與第 3 版比較')
            ->where('revisions.1.changes.1.kind', 'added')
            ->where('revisions.2.changes.0.label', '第 2 版已清除，以下與第 1 版比較')
            ->where('revisions.3.changes.0.kind', 'created'));
    }

    public function test_textbook_set_revisions_are_kept(): void
    {
        $this->at('90 days');
        $set = $this->vocabSet();
        CurriculumRef::create(['language_code' => 'vi', 'volume' => 1, 'lesson' => 1, 'set_id' => $set->id]);
        $this->at('60 days');
        $this->writeVocab($set, [['quả chuối', '香蕉']]);

        $this->prune();

        $this->assertSame(2, $set->revisions()->count());
    }

    public function test_deleted_activities_are_removed_after_thirty_days_with_their_attempts(): void
    {
        $set = $this->vocabSet();
        $old = $this->activity($set);
        $oldAttempt = $this->attempt($old);
        $recent = $this->activity($set);
        $this->attempt($recent);
        $this->at('31 days');
        $old->delete();
        $this->at('10 days');
        $recent->delete();

        $result = $this->prune();

        $this->assertSame(1, $result['activities']);
        $this->assertSame(1, $result['attempts']);
        $this->assertNull(Activity::withTrashed()->find($old->id));
        $this->assertModelMissing($oldAttempt);
        $this->assertNotNull(Activity::withTrashed()->find($recent->id));
        $this->assertModelExists($set);
    }

    public function test_deleted_sets_are_removed_after_thirty_days_with_everything_only_they_use(): void
    {
        $this->at('60 days');
        $image = $this->media();
        $old = Set::factory()->for($this->teacher, 'owner')->create();
        $this->writeVocab($old, [['quả chuối', '香蕉', $image->id], ['quả táo', '蘋果']]);
        $this->writeVocab($old, [['quả chuối', '香蕉', $image->id], ['quả táo', '蘋果'], ['quả cam', '柳橙']]);
        $activity = $this->activity($old);
        $attempt = $this->attempt($activity);
        SetReview::create(['set_id' => $old->id, 'set_revision_id' => $old->current_revision_id, 'user_id' => $this->teacher->id, 'action' => 'requested']);
        $copy = Set::factory()->for($this->teacher, 'owner')->create(['forked_from_id' => $old->id]);
        $this->writeVocab($copy, [['quả chuối', '香蕉', $image->id]]);
        $recent = $this->vocabSet();
        $items = $old->entries()->pluck('item_id');

        $this->at('31 days');
        $this->actingAs($this->teacher)->delete("/sets/{$old->id}")->assertRedirect();
        $this->at('10 days');
        $this->actingAs($this->teacher)->delete("/sets/{$recent->id}")->assertRedirect();

        $result = $this->prune();

        $this->assertNull(Set::withTrashed()->find($old->id));
        $this->assertSame(0, SetRevision::where('set_id', $old->id)->count());
        $this->assertSame(0, SetEntry::where('set_id', $old->id)->count());
        $this->assertSame(0, SetReview::where('set_id', $old->id)->count());
        $this->assertSame(0, Item::withTrashed()->whereIn('id', $items)->count());
        $this->assertNull(Activity::withTrashed()->find($activity->id));
        $this->assertModelMissing($attempt);
        $expected = ['attempts' => 1, 'activities' => 1, 'sets' => 1, 'items' => 3, 'set_revisions' => 2, 'media' => 0];
        $this->assertSame($expected, array_intersect_key($result, $expected));

        // 複製出去的題組不受影響，插圖仍在用
        $this->assertModelExists($copy);
        $this->assertModelExists($image);
        Storage::disk('public')->assertExists($image->path);
        $this->actingAs($this->teacher)->get("/sets/{$copy->id}")->assertOk();

        // 刪除不到 30 天的題組還在
        $this->assertNotNull(Set::withTrashed()->find($recent->id));
    }

    public function test_items_removed_from_sets_are_deleted_after_thirty_days(): void
    {
        $this->at('60 days');
        $set = $this->vocabSet();
        $removed = $set->entries()->get()->last()?->item;
        $this->assertNotNull($removed);
        $this->at('31 days');
        $this->writeVocab($set, [['quả chuối', '香蕉']]);
        $this->assertSoftDeleted($removed);

        $this->prune();

        $this->assertNull(Item::withTrashed()->find($removed->id));
        $this->assertSame(1, $set->entries()->count());
    }

    public function test_media_no_longer_used_are_deleted_with_their_files(): void
    {
        $this->at('60 days');
        $attached = $this->media();
        $inQuiz = $this->media('audio');
        $inOldRevision = $this->media();
        $replaced = $this->media();
        $unused = $this->media();

        $vocab = Set::factory()->for($this->teacher, 'owner')->create();
        $this->writeVocab($vocab, [['quả chuối', '香蕉', $inOldRevision->id], ['quả táo', '蘋果']]);
        // 這一版有作答紀錄，所以版本與其中的插圖都保留
        $this->attempt($this->activity($vocab));
        $this->at('50 days');
        $this->writeVocab($vocab, [['quả chuối', '香蕉', $attached->id], ['quả táo', '蘋果', $replaced->id]]);
        $this->at('40 days');
        $this->writeVocab($vocab, [['quả chuối', '香蕉', $attached->id], ['quả táo', '蘋果']]);

        $quiz = Set::factory()->quiz()->for($this->teacher, 'owner')->create();
        app(SetWriter::class)->write($quiz, ['entries' => [['question' => [
            'stem' => ['text' => '聽一聽，這是哪個詞？', 'audio_id' => $inQuiz->id],
            'options' => [
                ['id' => 'a', 'text' => 'cảm ơn', 'correct' => true],
                ['id' => 'b', 'text' => 'xin chào', 'correct' => false],
            ],
        ]]]], $this->teacher);

        // 剛上傳、還沒儲存的
        $this->at('1 day');
        $justUploaded = $this->media();

        $result = $this->prune();

        $this->assertSame(2, $result['media']);
        $this->assertSame(4, $result['files']);
        foreach ([$unused, $replaced] as $media) {
            $this->assertModelMissing($media);
            Storage::disk('public')->assertMissing([$media->path, (string) $media->thumbnail_path]);
        }
        foreach ([$attached, $inQuiz, $inOldRevision, $justUploaded] as $media) {
            $this->assertModelExists($media);
            Storage::disk('public')->assertExists($media->path);
        }
    }

    public function test_files_without_media_records_are_deleted_after_a_week(): void
    {
        $disk = Storage::disk('public');
        $disk->put('media/old-orphan.webp', 'x');
        $disk->put('media/new-orphan.webp', 'x');
        touch($disk->path('media/old-orphan.webp'), $this->now->subDays(8)->getTimestamp());
        touch($disk->path('media/new-orphan.webp'), $this->now->subDay()->getTimestamp());
        $kept = $this->media();

        $result = $this->prune();

        $this->assertSame(1, $result['files']);
        $disk->assertMissing('media/old-orphan.webp');
        $disk->assertExists(['media/new-orphan.webp', $kept->path, (string) $kept->thumbnail_path]);
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        $activity = $this->activity($this->vocabSet());
        $this->at('13 months');
        $attempt = $this->attempt($activity);
        $unused = $this->media();

        $this->travelTo($this->now);
        $this->artisan('kancil:prune --dry-run')
            ->expectsOutputToContain('dry run')
            ->expectsTable(['資料', '會刪除'], [
                ['作答紀錄', 1], ['活動', 0], ['題組', 0], ['詞條', 0], ['題組版本', 0], ['媒體', 1], ['媒體檔案', 2],
            ])
            ->assertSuccessful();

        $this->assertModelExists($attempt);
        $this->assertModelExists($unused);
        Storage::disk('public')->assertExists([$unused->path, (string) $unused->thumbnail_path]);

        $this->artisan('kancil:prune')->assertSuccessful();

        $this->assertModelMissing($attempt);
        $this->assertModelMissing($unused);
        Storage::disk('public')->assertMissing($unused->path);
    }

    public function test_pruning_is_scheduled_daily_after_the_backup(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'kancil:prune'));

        $this->assertCount(1, $events);
        // 台灣時間凌晨 4 點，備份在 3 點（docs/deploy.md）
        $this->assertSame('0 4 * * *', $events->first()?->expression);
        $this->assertSame('Asia/Taipei', $events->first()?->timezone);
    }
}
