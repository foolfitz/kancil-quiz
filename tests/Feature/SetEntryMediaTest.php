<?php

namespace Tests\Feature;

use App\Corpus\SetCopier;
use App\Corpus\SetWriter;
use App\Models\Item;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use App\Support\Ulid;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 問答題引用的媒體對照表 set_entry_media（docs/SPEC.md 第 5 節）：payload 寫入時由 SetEntry 同步，
 * 創作者頁面的統計（App\Profile\TeacherStats）與 kancil:prune 的媒體清除（App\Support\Pruner）都靠它，
 * 不必讀出每一題。對照表與 payload 一致的總檢查在 Tests\TestCase，每個測試結束時執行。
 */
class SetEntryMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
    }

    private function media(string $kind = 'image', ?User $uploader = null): Media
    {
        return Media::create([
            'kind' => $kind,
            'path' => 'media/'.fake()->uuid().($kind === 'image' ? '.webp' : '.m4a'),
            'mime' => $kind === 'image' ? 'image/webp' : 'audio/mp4',
            'bytes' => 10,
            'uploaded_by' => ($uploader ?? $this->teacher)->id,
        ]);
    }

    /**
     * 一題：題幹可以有音檔與圖片，兩個選項可以各有圖片（同一張圖可以出現兩次）。
     *
     * @param  list<string|null>  $optionImages
     * @return array{stem: array<string, mixed>, options: list<array<string, mixed>>}
     */
    private function question(?string $audio = null, ?string $image = null, array $optionImages = []): array
    {
        $options = [
            ['id' => 'a', 'text' => 'cảm ơn', 'image_id' => $optionImages[0] ?? null, 'correct' => true],
            ['id' => 'b', 'text' => 'xin chào', 'image_id' => $optionImages[1] ?? null, 'correct' => false],
        ];

        return ['stem' => ['text' => '聽一聽，這是哪個詞？', 'audio_id' => $audio, 'image_id' => $image], 'options' => $options];
    }

    /**
     * @param  list<array{stem: array<string, mixed>, options: list<array<string, mixed>>}>  $questions
     */
    private function write(Set $set, array $questions): void
    {
        $ids = $set->entries()->pluck('id')->all();
        app(SetWriter::class)->write($set, ['entries' => array_map(fn (array $question, int $i) => [
            'id' => $ids[$i] ?? null,
            'question' => $question,
        ], $questions, array_keys($questions))], $this->teacher);
    }

    private function quiz(): Set
    {
        return Set::factory()->quiz()->for($this->teacher, 'owner')->create();
    }

    /**
     * 題組每一題在對照表中的媒體 ID，依題目順序。
     *
     * @return list<list<string>>
     */
    private function references(Set $set): array
    {
        return $set->entries()->get()
            ->map(fn (SetEntry $entry) => $entry->media()->orderBy('media.id')->pluck('media.id')->all())
            ->all();
    }

    /**
     * @param  list<Media>  $media
     * @return list<string>
     */
    private function ids(array $media): array
    {
        $ids = array_map(fn (Media $media) => $media->id, $media);
        sort($ids);

        return $ids;
    }

    public function test_the_references_follow_the_payload_when_questions_are_saved_changed_and_removed(): void
    {
        $audio = $this->media('audio');
        [$a, $b, $c] = [$this->media(), $this->media(), $this->media()];
        $set = $this->quiz();

        // 同一張圖在一題中出現兩次只記一列；沒有媒體的題目沒有列
        $this->write($set, [$this->question($audio->id, $a->id, [$b->id, $b->id]), $this->question()]);
        [$first, $second] = $set->entries()->pluck('id')->all();
        $this->assertSame([$this->ids([$audio, $a, $b]), []], $this->references($set));

        // 改題目：第一題換成只有 c，第二題加上 a
        $this->write($set, [$this->question(null, $c->id), $this->question(null, null, [$a->id])]);
        $this->assertSame([$first, $second], $set->entries()->pluck('id')->all());
        $this->assertSame([[$c->id], [$a->id]], $this->references($set));

        // 刪掉第二題，它的列跟著刪；媒體本身還在
        $this->write($set, [$this->question(null, $c->id)]);
        $this->assertSame([[$c->id]], $this->references($set));
        $this->assertSame(0, DB::table('set_entry_media')->where('set_entry_id', $second)->count());
        $this->assertModelExists($a);
        $this->assertSame([], $a->entries()->pluck('set_entries.id')->all());
        $this->assertSame([$first], $c->entries()->pluck('set_entries.id')->all());
    }

    public function test_vocab_entries_have_no_references_of_their_own(): void
    {
        $image = $this->media();
        $set = Set::factory()->for($this->teacher, 'owner')->create();
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [['item' => ['text' => 'quả chuối', 'translation_zh' => '香蕉', 'image_id' => $image->id]]],
        ], $this->teacher);

        // 詞彙組的媒體在詞條上（item_media）
        $this->assertSame(0, DB::table('set_entry_media')->count());
        $this->assertSame(1, $image->items()->count());
    }

    public function test_copying_a_quiz_set_copies_the_references(): void
    {
        $audio = $this->media('audio');
        $image = $this->media();
        $set = $this->quiz();
        $this->write($set, [$this->question($audio->id, $image->id), $this->question(null, null, [$image->id])]);
        $colleague = User::factory()->create();

        $copy = app(SetCopier::class)->copy($set, $colleague);

        $this->assertSame([$this->ids([$audio, $image]), [$image->id]], $this->references($copy));
        // 媒體檔不可變，複製品與來源引用同一批媒體
        $this->assertSame(4, $image->entries()->count());
        $this->assertSame(2, $audio->entries()->count());
    }

    public function test_deleting_a_set_for_good_removes_its_references_but_not_the_media(): void
    {
        $image = $this->media();
        $set = $this->quiz();
        $this->write($set, [$this->question(null, $image->id)]);
        $this->assertSame(1, DB::table('set_entry_media')->count());

        $set->forceDelete();

        $this->assertSame(0, DB::table('set_entry_media')->count());
        $this->assertModelExists($image);
    }

    public function test_a_media_that_questions_still_use_cannot_be_deleted(): void
    {
        $image = $this->media();
        $this->write($this->quiz(), [$this->question(null, $image->id)]);

        // 清除媒體的程式只刪沒有引用的（App\Support\Pruner）；還有題目引用的刪不掉，外鍵會擋
        $this->expectException(QueryException::class);
        $image->delete();
    }

    public function test_the_migration_backfills_the_references_of_existing_questions(): void
    {
        $audio = $this->media('audio');
        $image = $this->media();
        $set = $this->quiz();
        $this->write($set, [$this->question($audio->id, $image->id, [$image->id]), $this->question()]);
        [$first] = $set->entries()->pluck('id')->all();
        // 詞彙組的詞條沒有 payload，不會有列
        $vocab = Set::factory()->for($this->teacher, 'owner')->create();
        $vocab->entries()->create(['position' => 0, 'item_id' => Item::create([
            'language_code' => 'vi', 'text' => 'quả chuối', 'translation_zh' => '香蕉', 'owner_id' => $this->teacher->id,
        ])->id]);
        // payload 引用的媒體已經不存在時（斷掉的引用），對照表放不進去，略過（繞過 model 事件寫入才做得到）
        DB::table('set_entries')->insert([
            'id' => Ulid::make(), 'set_id' => $set->id, 'position' => 2, 'created_at' => now(), 'updated_at' => now(),
            'payload' => json_encode($this->question(null, Ulid::make()), JSON_THROW_ON_ERROR),
        ]);

        // migration 之前的狀態：表是空的（在測試的交易中不能真的 drop 再 create）
        DB::table('set_entry_media')->delete();
        $migration = require database_path('migrations/2026_10_06_200000_create_set_entry_media_table.php');
        $migration->backfill();

        $rows = DB::table('set_entry_media')->get()->map(fn (object $row) => [$row->set_entry_id, $row->media_id])->all();
        sort($rows);
        $this->assertSame(array_map(fn (string $id) => [$first, $id], $this->ids([$audio, $image])), $rows);
    }

    public function test_the_references_stay_in_sync_through_the_editor_and_the_copy_button(): void
    {
        $audio = $this->media('audio');
        [$a, $b] = [$this->media(), $this->media()];
        $this->actingAs($this->teacher)->post('/sets', ['kind' => 'quiz', 'title' => '打招呼', 'language_code' => 'vi', 'license' => 'CC-BY-4.0'])->assertRedirect();
        $set = Set::latest('created_at')->firstOrFail();
        $content = fn (array $questions) => [
            'title' => '打招呼', 'language_code' => 'vi', 'license' => 'CC-BY-4.0',
            'entries' => array_map(fn (array $question) => ['id' => null, 'question' => $question], $questions),
        ];

        $this->put("/sets/{$set->id}", $content([$this->question($audio->id, $a->id, [$b->id, $b->id]), $this->question()]))->assertSessionHasNoErrors();
        $this->assertSame([$this->ids([$audio, $a, $b]), []], $this->references($set));

        // 編輯頁重新送出整份內容時沒有帶 id 的題目是新的一題，舊的刪掉
        $this->put("/sets/{$set->id}", $content([$this->question(null, null, [$a->id])]))->assertSessionHasNoErrors();
        $this->assertSame([[$a->id]], $this->references($set));

        $this->post("/sets/{$set->id}/copy")->assertRedirect();
        $copy = Set::where('forked_from_id', $set->id)->firstOrFail();
        $this->assertSame([[$a->id]], $this->references($copy));

        $this->assertQuizMediaReferencesAreInSync();
    }
}
