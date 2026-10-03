<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Models\CurriculumRef;
use App\Models\Item;
use App\Models\Language;
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

    public function test_a_share_link_lets_colleagues_view_and_copy_until_it_is_revoked(): void
    {
        // T-17：產生分享連結後題組改為 unlisted
        $this->actingAs($this->author)->post("/sets/{$this->set->id}/share")->assertRedirect();
        $this->set->refresh();
        $this->assertSame('unlisted', $this->set->visibility);
        $token = (string) $this->set->share_token;
        $this->assertSame(32, strlen($token));

        $this->get("/sets/{$this->set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('sharing.visibility', 'unlisted')
            ->where('sharing.share_url', route('sets.shared', $token)));

        // 同事要登入；只知道題組 ID 不夠，要有連結
        auth()->logout();
        $this->get("/shared/{$token}")->assertRedirect('/login');
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}")->assertForbidden();
        $this->post("/sets/{$this->set->id}/copy")->assertForbidden();
        $this->post("/sets/{$this->set->id}/copy", ['token' => 'wrong'])->assertForbidden();

        $this->get("/shared/{$token}")->assertInertia(fn (Assert $page) => $page
            ->component('sets/Show')
            ->where('token', $token)
            ->where('can.manage', false)
            ->has('entries', 2));
        $this->post("/sets/{$this->set->id}/copy", ['token' => $token])->assertRedirect();
        $this->assertSame(1, Set::where('owner_id', $this->colleague->id)->count());

        // 收回後舊連結失效，已複製的題組不受影響；再次產生是新的連結
        $this->actingAs($this->author)->delete("/sets/{$this->set->id}/share")->assertRedirect();
        $this->set->refresh();
        $this->assertSame('private', $this->set->visibility);
        $this->assertNull($this->set->share_token);

        $this->actingAs($this->colleague)->get("/shared/{$token}")->assertNotFound();
        $this->post("/sets/{$this->set->id}/copy", ['token' => $token])->assertForbidden();
        $this->assertSame(1, Set::where('owner_id', $this->colleague->id)->count());

        $this->actingAs($this->author)->post("/sets/{$this->set->id}/share");
        $this->assertNotSame($token, $this->set->fresh()?->share_token);
    }

    public function test_only_the_owner_manages_the_share_link(): void
    {
        $this->actingAs($this->colleague)->post("/sets/{$this->set->id}/share")->assertForbidden();

        $this->publish($this->set);
        $this->actingAs($this->author)->post("/sets/{$this->set->id}/share")->assertStatus(409);
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}/edit")->assertForbidden();
    }

    private function curator(string ...$languages): User
    {
        $curator = User::factory()->create(['name' => '陳審核']);
        $curator->assignRole('curator');
        $curator->reviewLanguages()->attach($languages);

        return $curator;
    }

    public function test_a_set_is_published_only_after_a_curator_approves_it(): void
    {
        $curator = $this->curator('vi');
        $otherLanguage = $this->curator('id');

        // T-12：擁有者申請公開
        $this->actingAs($this->author)->post("/sets/{$this->set->id}/publication", ['note' => '適合三年級'])->assertRedirect();
        $this->set->refresh();
        $this->assertSame('pending', $this->set->review_status);
        $this->assertSame('private', $this->set->visibility);
        $this->actingAs($this->author)->post("/sets/{$this->set->id}/publication")->assertStatus(409);

        // 待審的題組只有負責該語言的審核者看得到
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}")->assertForbidden();
        $this->actingAs($this->colleague)->get('/reviews')->assertForbidden();
        $this->actingAs($this->colleague)->post("/sets/{$this->set->id}/review", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($otherLanguage)->get("/sets/{$this->set->id}")->assertForbidden();
        $this->actingAs($otherLanguage)->get('/reviews')->assertInertia(fn (Assert $page) => $page->has('pending', 0));

        $this->actingAs($curator)->get('/reviews')->assertInertia(fn (Assert $page) => $page
            ->component('reviews/Index')
            ->where('languages', ['越南語'])
            ->has('pending', 1)
            ->where('pending.0.id', $this->set->id)
            ->where('pending.0.note', '適合三年級'));
        $this->get("/sets/{$this->set->id}")->assertInertia(fn (Assert $page) => $page
            ->where('can.review', true)
            ->where('can.edit', false)
            ->where('reviews.0.action', 'requested'));

        // C-01：退回要附意見
        $this->post("/sets/{$this->set->id}/review", ['decision' => 'reject'])->assertSessionHasErrors('note');
        $this->post("/sets/{$this->set->id}/review", ['decision' => 'reject', 'note' => '第 2 題的拼字有誤'])->assertRedirect();
        $this->assertSame('rejected', $this->set->fresh()?->review_status);
        $this->actingAs($this->author)->get("/sets/{$this->set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('sharing.review_status', 'rejected')
            ->where('sharing.last_review.action', 'rejected')
            ->where('sharing.last_review.note', '第 2 題的拼字有誤'));

        // 修正後再申請，審核者通過後公開，分享連結也一併收回
        $this->post("/sets/{$this->set->id}/share");
        $this->post("/sets/{$this->set->id}/publication")->assertRedirect();
        $this->actingAs($curator)->post("/sets/{$this->set->id}/review", ['decision' => 'approve'])->assertRedirect();
        $this->set->refresh();
        $this->assertSame('public', $this->set->visibility);
        $this->assertSame('approved', $this->set->review_status);
        $this->assertNull($this->set->share_token);
        $this->assertSame($this->set->current_revision_id, $this->set->reviews()->first()?->set_revision_id);
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}")->assertOk();
        $this->actingAs($curator)->post("/sets/{$this->set->id}/review", ['decision' => 'approve'])->assertStatus(409);

        // 已公開的題組，審核者可以附上原因下架
        $this->post("/sets/{$this->set->id}/review", ['decision' => 'unpublish', 'note' => '圖片沒有授權'])->assertRedirect();
        $this->assertSame('private', $this->set->fresh()?->visibility);
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}")->assertForbidden();

        $this->assertSame(
            ['unpublished', 'approved', 'requested', 'rejected', 'requested'],
            $this->set->reviews()->pluck('action')->all(),
        );
    }

    public function test_owners_can_withdraw_requests_and_unpublish_their_own_sets(): void
    {
        $this->actingAs($this->author)->delete("/sets/{$this->set->id}/publication")->assertStatus(409);

        $this->post("/sets/{$this->set->id}/publication");
        $this->delete("/sets/{$this->set->id}/publication")->assertRedirect();
        $this->assertSame('none', $this->set->fresh()?->review_status);

        $this->publish($this->set);
        $this->delete("/sets/{$this->set->id}/publication")->assertRedirect();
        $this->set->refresh();
        $this->assertSame('private', $this->set->visibility);
        $this->assertSame('none', $this->set->review_status);
    }

    public function test_curators_cannot_review_their_own_sets_but_admins_can(): void
    {
        $curator = $this->curator('vi');
        $own = Set::factory()->for($curator, 'owner')->create(['review_status' => 'pending']);
        $this->actingAs($curator)->post("/sets/{$own->id}/review", ['decision' => 'approve'])->assertForbidden();
        $this->get('/reviews')->assertInertia(fn (Assert $page) => $page->has('pending', 0));

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/reviews')->assertInertia(fn (Assert $page) => $page
            ->where('languages', null)
            ->has('pending', 1));
        $this->post("/sets/{$own->id}/review", ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('public', $own->fresh()?->visibility);
    }

    public function test_the_library_lists_and_searches_public_sets(): void
    {
        $this->publish($this->set);
        $indonesian = Set::factory()->for($this->colleague, 'owner')->create([
            'title' => 'Salam', 'language_code' => 'id', 'tags' => ['問候', '水果'],
        ]);
        $this->write($indonesian, [['selamat pagi', '早安', null]], $this->colleague);
        $this->publish($indonesian);
        Set::factory()->for($this->colleague, 'owner')->create(['title' => '私人', 'visibility' => 'private']);
        Set::factory()->for($this->colleague, 'owner')->create(['title' => '分享中', 'visibility' => 'unlisted', 'share_token' => 'x']);
        Set::factory()->for($this->colleague, 'owner')->create(['title' => '待審', 'review_status' => 'pending']);

        $list = function (string $query) {
            $response = $this->actingAs($this->colleague)->get('/library'.($query === '' ? '' : "?{$query}"));
            $response->assertOk();

            return collect($response->viewData('page')['props']['sets']['data'])->pluck('title')->sort()->values()->all();
        };

        $this->assertSame(['Salam', '水果'], $list(''));
        $this->assertSame(['Salam'], $list('language=id'));
        $this->assertSame(['水果'], $list('volume=3&lesson=2'));
        $this->assertSame([], $list('volume=3&lesson=1'));
        $this->assertSame(['Salam'], $list('tag='.urlencode('問候')));
        $this->assertSame(['Salam'], $list('q='.urlencode('早安')));
        $this->assertSame(['水果'], $list('q=chu'));

        $this->get('/library')->assertInertia(fn (Assert $page) => $page
            ->where('tags.0', '水果')
            ->has('languages', 2));

        // 未開放的語言不列出（docs/SPEC.md 1.4）
        Language::whereKey('id')->update(['enabled' => false]);
        $this->assertSame(['水果'], $list(''));
    }

    public function test_curators_correct_public_sets_and_the_changes_are_kept(): void
    {
        $curator = $this->curator('vi');
        $ids = $this->set->entries()->pluck('id')->all();
        $payload = fn (string $language, string $apple) => [
            'title' => '水果', 'language_code' => $language, 'license' => 'CC-BY-4.0',
            'tags' => ['水果'],
            'curriculum_ref_ids' => $language === 'vi' ? $this->set->curriculumRefs()->pluck('curriculum_refs.id')->all() : [],
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [
                ['id' => $ids[0], 'item' => ['text' => 'quả chuối', 'romanization' => null, 'translation_zh' => '香蕉', 'audio_ids' => [], 'image_id' => $this->image->id]],
                ['id' => $ids[1], 'item' => ['text' => 'quả táo', 'romanization' => null, 'translation_zh' => $apple, 'audio_ids' => [], 'image_id' => null]],
            ],
        ];

        // 未公開的題組不能修正；其他語言的審核者也不行
        $this->actingAs($curator)->get("/sets/{$this->set->id}/edit")->assertForbidden();
        $this->publish($this->set);
        $this->actingAs($this->curator('id'))->get("/sets/{$this->set->id}/edit")->assertForbidden();

        // C-03：負責該語言的審核者可以修正公開的題組，但看不到活動，也不能分享或刪除
        $this->actingAs($curator)->get("/sets/{$this->set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->component('sets/Edit')
            ->where('can.manage', false)
            ->where('set.owner', '王老師')
            ->where('sharing', null)
            ->where('activities', []));
        $this->put("/sets/{$this->set->id}", $payload('id', '青蘋果'))->assertSessionHasErrors('language_code');
        $this->put("/sets/{$this->set->id}", $payload('vi', '青蘋果'))->assertSessionHasNoErrors();
        $this->delete("/sets/{$this->set->id}")->assertForbidden();
        $this->post("/sets/{$this->set->id}/share")->assertForbidden();

        $this->set->refresh();
        $this->assertSame('public', $this->set->visibility);
        $this->assertSame($curator->id, $this->set->currentRevision?->created_by);
        // 審核者的修正不算詞條作者
        $this->assertSame('王老師', $this->set->currentRevision?->content()->authors[0]->name);

        // 修訂紀錄：擁有者看得到是誰改了什麼
        $this->actingAs($this->author)->get("/sets/{$this->set->id}")->assertInertia(fn (Assert $page) => $page
            ->where('revisions.0.number', 2)
            ->where('revisions.0.created_by', '陳審核')
            ->where('revisions.0.changes', [[
                'kind' => 'changed', 'label' => '修改', 'before' => 'quả táo（蘋果）', 'after' => 'quả táo（青蘋果）',
            ]])
            ->where('revisions.1.changes.0.kind', 'created'));

        // 一般老師看公開題組時看不到修訂紀錄
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}")->assertInertia(fn (Assert $page) => $page
            ->where('revisions', []));
    }
}
