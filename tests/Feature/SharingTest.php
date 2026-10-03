<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Models\CurriculumRef;
use App\Models\Item;
use App\Models\Media;
use App\Models\Set;
use App\Models\User;
use App\Support\KancilFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 共備：檢視與複製題組、分享連結、公開申請與審核、共備庫（docs/SPEC.md M2）。
 */
class SharingTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $colleague;

    private Set $set;

    private Media $image;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['name' => '王老師']);
        $this->colleague = User::factory()->create(['name' => '李老師']);
        $this->image = Media::create([
            'kind' => 'image', 'path' => 'media/banana.webp', 'mime' => 'image/webp', 'bytes' => 10,
            'uploaded_by' => $this->author->id,
        ]);

        $this->set = Set::factory()->for($this->author, 'owner')->create(['tags' => ['水果']]);
        $this->set->curriculumRefs()->attach(CurriculumRef::create(['language_code' => 'vi', 'volume' => 3, 'lesson' => 2]));
        $this->write($this->set, [['quả chuối', '香蕉', $this->image->id], ['quả táo', '蘋果', null]], $this->author);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null}>  $words
     */
    private function write(Set $set, array $words, User $by): void
    {
        $ids = $set->entries()->pluck('id')->all();
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => array_map(fn ($word, $i) => [
                'id' => $ids[$i] ?? null,
                'item' => ['text' => $word[0], 'translation_zh' => $word[1], 'image_id' => $word[2]],
            ], $words, array_keys($words)),
        ], $by);
        $set->refresh();
    }

    private function publish(Set $set): void
    {
        $set->update(['visibility' => 'public', 'review_status' => 'approved']);
    }

    public function test_private_sets_are_only_visible_to_their_owner(): void
    {
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}")->assertForbidden();
        $this->actingAs($this->colleague)->post("/sets/{$this->set->id}/copy")->assertForbidden();

        $this->actingAs($this->author)->get("/sets/{$this->set->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('sets/Show')
                ->where('can.manage', true)
                ->where('set.authors', ['王老師'])
                ->where('set.curriculum.0.volume', 3)
                ->has('entries', 2)
                ->where('entries.0.question.text', 'quả chuối')
                ->where('entries.0.question.image', fn (string $url) => str_ends_with($url, 'media/banana.webp')));
    }

    public function test_teachers_copy_public_sets_with_their_items_and_attribution(): void
    {
        $this->publish($this->set);

        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['manage' => false, 'edit' => false, 'review' => false])
                ->where('reviews', []));

        $this->post("/sets/{$this->set->id}/copy")->assertRedirect();
        $copy = Set::where('owner_id', $this->colleague->id)->firstOrFail();

        // 複製出來的題組是自己的、不公開，記下來源（docs/SPEC.md 第 5 節）
        $this->assertSame($this->set->id, $copy->forked_from_id);
        $this->assertSame('private', $copy->visibility);
        $this->assertSame(['水果'], $copy->tags);
        $this->assertSame(1, $copy->curriculumRefs()->count());

        // 詞條也複製，作者沿用原作者；媒體共用
        $items = Item::where('owner_id', $this->colleague->id)->orderBy('text')->get();
        $this->assertCount(2, $items);
        $this->assertNotNull($items[0]->forked_from_id);
        $this->assertSame([['name' => '王老師']], $items[0]->authors);
        $this->assertSame([$this->image->id], $items[0]->media()->pluck('media.id')->all());

        $content = $copy->currentRevision?->content();
        $this->assertNotNull($content);
        $this->assertSame([], app(KancilFormat::class)->setErrors($content));
        $this->assertEquals([(object) ['name' => '王老師'], (object) ['name' => '李老師']], $content->authors);

        // 原作者之後修改原詞條，不影響複製出去的題組（M2 驗收 1）
        $this->write($this->set, [['quả chuối', '香蕉（改）', $this->image->id], ['quả táo', '蘋果', null]], $this->author);
        $this->assertSame('香蕉', $copy->fresh()?->currentRevision?->content()->entries[0]->item->translation_zh);

        // 修改複製來的詞條時，把自己加進該詞條的作者
        $this->write($copy, [['quả chuối', '香蕉', $this->image->id], ['quả táo xanh', '青蘋果', null]], $this->colleague);
        $content = $copy->fresh()?->currentRevision?->content();
        $this->assertEquals([(object) ['name' => '王老師'], (object) ['name' => '李老師']], $content?->entries[1]->item->authors);
        $this->assertEquals([(object) ['name' => '王老師']], $content?->entries[0]->item->authors);
    }

    public function test_a_teacher_can_duplicate_their_own_quiz_set(): void
    {
        $quiz = Set::factory()->quiz()->for($this->author, 'owner')->create();
        app(SetWriter::class)->write($quiz, ['entries' => [[
            'id' => null,
            'question' => [
                'stem' => ['text' => '「謝謝」的越南語是？', 'audio_id' => null, 'image_id' => $this->image->id],
                'options' => [
                    ['id' => 'a', 'text' => 'cảm ơn', 'image_id' => null, 'correct' => true],
                    ['id' => 'b', 'text' => 'xin chào', 'image_id' => null, 'correct' => false],
                ],
            ],
        ]]], $this->author);

        $this->actingAs($this->author)->post("/sets/{$quiz->id}/copy")->assertRedirect();
        $copy = Set::where('forked_from_id', $quiz->id)->firstOrFail();

        $content = $copy->currentRevision?->content();
        $this->assertSame('「謝謝」的越南語是？', $content?->entries[0]->question->stem->text);
        $this->assertSame("media/{$this->image->id}.webp", $content?->entries[0]->question->stem->image->src);
        $this->assertEquals([(object) ['name' => '王老師']], $content?->authors);
    }
}
