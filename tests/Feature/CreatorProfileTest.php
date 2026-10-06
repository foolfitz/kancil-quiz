<?php

namespace Tests\Feature;

use App\Auth\AccountDeletion;
use App\Corpus\MediaCredits;
use App\Corpus\SetCopier;
use App\Corpus\SetWriter;
use App\Curriculum\Textbook;
use App\Models\Contribution;
use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\Set;
use App\Models\User;
use App\Profile\Contributions;
use App\Profile\TeacherStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

/**
 * 創作者資料與創作者頁面（docs/SPEC.md T-20）：署名自動填進作者、新題組的預設授權、
 * 只給登入老師看的創作者頁面，以及由公開內容算出的貢獻統計。
 */
class CreatorProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['name' => '王老師', 'email' => 'wang@example.com', 'google_id' => '1001']);
        $this->teacher->assignRole('teacher');
        $this->colleague = User::factory()->create(['name' => '李老師', 'email' => 'li@example.com']);
        $this->colleague->assignRole('teacher');
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string|null}>  $words  [目標語, 中文, 圖片 ID]
     */
    private function vocabSet(User $owner, string $title, array $words, bool $public = true, ?User $by = null): Set
    {
        $set = Set::factory()->for($owner, 'owner')->create(['language_code' => 'id', 'title' => $title]);
        $this->write($set, $words, $by ?? $owner);
        if ($public) {
            $set->update(['visibility' => 'public', 'review_status' => 'approved']);
        }

        return $set->refresh();
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string|null}>  $words
     */
    private function write(Set $set, array $words, User $by): void
    {
        $ids = $set->entries()->pluck('id')->all();
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => array_map(fn (array $word, int $i) => [
                'id' => $ids[$i] ?? null,
                'item' => ['text' => $word[0], 'translation_zh' => $word[1], 'image_id' => $word[2] ?? null],
            ], $words, array_keys($words)),
        ], $by);
    }

    private function image(User $uploader): Media
    {
        return Media::create([
            'kind' => 'image', 'path' => 'media/'.fake()->uuid().'.webp', 'mime' => 'image/webp', 'bytes' => 10,
            'uploaded_by' => $uploader->id,
        ]);
    }

    /**
     * 教材題組：由教材帳號擁有、公開、照原教材的授權（3.6）。
     */
    private function textbookSet(): Set
    {
        $set = Set::factory()->for(Textbook::owner(), 'owner')->create([
            'language_code' => 'id', 'title' => '第 1 冊第 1 課', 'license' => Textbook::LICENSE, 'authors' => Textbook::AUTHORS,
            'visibility' => 'public', 'review_status' => 'approved',
        ]);
        $this->write($set, [['ayah', '爸爸'], ['ibu', '媽媽']], Textbook::owner());
        CurriculumRef::create(['language_code' => 'id', 'volume' => 1, 'lesson' => 1, 'title_zh' => '你好', 'set_id' => $set->id]);

        return $set->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(array $overrides = []): array
    {
        return [
            'attribution_name' => '王小明（臺北市○○國小）',
            'attribution_url' => 'https://example.org/wang',
            'default_license' => 'CC-BY-SA-4.0',
            'school' => '臺北市○○國小',
            'teaching_languages' => ['id', 'vi'],
            'bio' => "教印尼語五年。\n喜歡用遊戲帶詞彙。",
            ...$overrides,
        ];
    }

    public function test_the_settings_page_shows_the_creator_profile(): void
    {
        $this->actingAs($this->teacher)->get('/settings/creator')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Creator')
                ->where('profile.attribution_name', null)
                ->where('profile.default_license', 'CC-BY-4.0')
                ->where('profile.teaching_languages', [])
                ->where('licenses', Set::LICENSES)
                ->where('profileUrl', route('teachers.show', $this->teacher->public_id))
                // 教的語言不限已開放的語言
                ->has('languages', 7));
    }

    public function test_teachers_update_their_creator_profile(): void
    {
        $this->actingAs($this->teacher);

        $this->patch('/settings/creator', $this->profile(['attribution_url' => 'ftp://example.org']))->assertSessionHasErrors('attribution_url');
        $this->patch('/settings/creator', $this->profile(['attribution_url' => 'javascript:alert(1)']))->assertSessionHasErrors('attribution_url');
        $this->patch('/settings/creator', $this->profile(['attribution_url' => 'example.org']))->assertSessionHasErrors('attribution_url');
        $this->patch('/settings/creator', $this->profile(['bio' => str_repeat('長', 501)]))->assertSessionHasErrors('bio');
        $this->patch('/settings/creator', $this->profile(['attribution_name' => str_repeat('長', 101)]))->assertSessionHasErrors('attribution_name');
        // 教材的授權不是老師能選的
        $this->patch('/settings/creator', $this->profile(['default_license' => 'CC-BY-NC-ND-4.0']))->assertSessionHasErrors('default_license');
        $this->patch('/settings/creator', $this->profile(['teaching_languages' => ['xx']]))->assertSessionHasErrors('teaching_languages.0');
        $this->assertNull($this->teacher->fresh()?->attribution_name);

        $this->patch('/settings/creator', $this->profile(['attribution_name' => '  王小明（臺北市○○國小） ']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/creator');

        $user = $this->teacher->fresh();
        $this->assertSame('王小明（臺北市○○國小）', $user->attribution_name);
        $this->assertSame('https://example.org/wang', $user->attribution_url);
        $this->assertSame('CC-BY-SA-4.0', $user->default_license);
        $this->assertSame('臺北市○○國小', $user->school);
        $this->assertSame(['id', 'vi'], $user->teaching_languages);
        $this->assertSame("教印尼語五年。\n喜歡用遊戲帶詞彙。", $user->bio);
        $this->assertSame(['name' => '王小明（臺北市○○國小）', 'url' => 'https://example.org/wang'], $user->author());

        // 空白的署名名稱：用帳號的名字；清掉的欄位存成 null
        $this->patch('/settings/creator', ['attribution_name' => ' ', 'attribution_url' => '', 'default_license' => 'CC-BY-4.0', 'teaching_languages' => [], 'bio' => ''])
            ->assertSessionHasNoErrors();
        $user = $this->teacher->fresh();
        $this->assertNull($user->attribution_name);
        $this->assertSame('王老師', $user->attributionName());
        $this->assertSame(['name' => '王老師'], $user->author());
        $this->assertNull($user->teaching_languages);
        $this->assertNull($user->bio);
    }

    /**
     * 署名自動填進上傳的媒體、修改複製來的詞條時加上的作者，以及匯出時的擁有者；
     * 已經寫下的署名是當時的快照，改了創作者資料不會跟著變（第 5 節）。
     */
    public function test_the_attribution_is_used_wherever_the_teacher_is_written_as_an_author(): void
    {
        Storage::fake('public');
        $this->teacher->update($this->profile());
        $author = ['name' => '王小明（臺北市○○國小）', 'url' => 'https://example.org/wang'];

        // 上傳的媒體：作者預設是署名（名字與網址），編輯頁的署名欄顯示署名名稱
        $response = $this->actingAs($this->teacher)->post('/media', [
            'kind' => 'image', 'file' => UploadedFile::fake()->image('banana.png', 40, 40), 'rights' => '1', 'license' => 'CC-BY-SA-4.0',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('author', '王小明（臺北市○○國小）');
        $image = Media::findOrFail($response->json('id'));
        $this->assertSame([$author], $image->authors);

        // 編輯頁把自己的署名名稱和其他人一起填進作者欄時，自己的那一個附上網址
        $this->assertSame([$author, ['name' => '陳同學']], MediaCredits::authors('王小明（臺北市○○國小）、陳同學', $this->teacher));
        $this->assertSame([$author], MediaCredits::authors('  ', $this->teacher));

        // 複製同事的公開題組後修改詞條：加上自己的署名
        $source = $this->vocabSet($this->colleague, '水果', [['pisang', '香蕉'], ['apel', '蘋果']]);
        $copy = app(SetCopier::class)->copy($source, $this->teacher);
        $this->write($copy, [['pisang', '香蕉', $image->id], ['apel hijau', '青蘋果']], $this->teacher);
        $content = $copy->fresh()?->currentRevision?->content();
        $this->assertNotNull($content);
        $this->assertEquals([(object) ['name' => '李老師'], (object) $author], $content->entries[1]->item->authors);
        $this->assertEquals([(object) ['name' => '李老師'], (object) $author], $content->authors);

        // 匯出：set.json 的作者與 LICENSE.txt 都有署名的網址
        $zipResponse = $this->actingAs($this->teacher)->get("/sets/{$copy->id}/export")->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipResponse->baseResponse->getFile()->getPathname()));
        $license = (string) $zip->getFromName('LICENSE.txt');
        $set = json_decode((string) $zip->getFromName('set.json'));
        $zip->close();
        $this->assertStringContainsString('作者：李老師、王小明（臺北市○○國小）（https://example.org/wang）', $license);
        $this->assertEquals((object) $author, $set->authors[1]);

        // 改了署名：媒體與詞條上已經寫下的不變，匯出時的擁有者跟著新的署名
        $this->teacher->update(['attribution_name' => '王小明', 'attribution_url' => null]);
        $this->assertSame([$author], $image->fresh()?->authors);
        $content = $copy->fresh()?->currentRevision?->content();
        $this->assertEquals((object) $author, $content?->entries[1]->item->authors[1]);
        $this->assertSame([['name' => '李老師'], ['name' => '王小明']], $copy->fresh()?->effectiveAuthors());
    }

    public function test_new_sets_default_to_the_teachers_license(): void
    {
        $this->actingAs($this->teacher)->get('/sets/create')
            ->assertInertia(fn (Assert $page) => $page->where('license', 'CC-BY-4.0'));

        $this->teacher->update(['default_license' => 'CC0-1.0']);
        $this->actingAs($this->teacher)->get('/sets/create')
            ->assertInertia(fn (Assert $page) => $page->where('license', 'CC0-1.0'));

        // 複製教材題組時，教材的授權不是老師能選的，改用自己的預設授權；一般的複製沿用來源的授權
        $copy = app(SetCopier::class)->copy($this->textbookSet(), $this->teacher);
        $this->assertSame('CC0-1.0', $copy->license);
        $shared = $this->vocabSet($this->colleague, '水果', [['pisang', '香蕉']]);
        $shared->update(['license' => 'CC-BY-SA-4.0']);
        $this->assertSame('CC-BY-SA-4.0', app(SetCopier::class)->copy($shared->fresh(), $this->teacher)->license);
    }

    public function test_the_profile_page_is_only_for_logged_in_teachers_and_never_shows_private_data(): void
    {
        $this->colleague->update($this->profile(['attribution_name' => '李大明', 'teaching_languages' => ['id']]));
        $this->vocabSet($this->colleague, '水果', [['pisang', '香蕉']]);
        $url = route('teachers.show', $this->colleague->public_id);
        $this->assertMatchesRegularExpression('#/teachers/[0-9A-HJKMNP-TV-Z]{26}$#', $url);

        $this->get($url)->assertRedirect('/login');

        $response = $this->actingAs($this->teacher)->get($url)->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('teachers/Show')
            ->where('teacher', [
                'id' => $this->colleague->public_id,
                'name' => '李大明',
                'url' => 'https://example.org/wang',
                'school' => '臺北市○○國小',
                'languages' => [['code' => 'id', 'name_zh' => '印尼語', 'name_native' => 'Bahasa Indonesia']],
                'bio' => "教印尼語五年。\n喜歡用遊戲帶詞彙。",
            ])
            ->where('isSelf', false)
            ->has('sets.data', 1)
            ->where('sets.data.0.title', '水果')
            ->where('sets.data.0.owner_url', $url)
            ->has('stats')
            ->has('calendar.days'));
        $props = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('li@example.com', $props);
        $this->assertStringNotContainsString('"李老師"', $props);

        $this->actingAs($this->colleague)->get($url)->assertInertia(fn (Assert $page) => $page->where('isSelf', true));

        // 遞增的 id 不是網址；不存在的 ULID、停用、已刪除的帳號與教材帳號都是 404
        $this->actingAs($this->teacher)->get("/teachers/{$this->colleague->id}")->assertNotFound();
        $this->actingAs($this->teacher)->get('/teachers/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
        $this->colleague->forceFill(['disabled_at' => now()])->save();
        $this->actingAs($this->teacher)->get($url)->assertNotFound();
        $this->colleague->forceFill(['disabled_at' => null])->save();
        app(AccountDeletion::class)->delete($this->colleague);
        $this->actingAs($this->teacher)->get($url)->assertNotFound();
        $textbook = Textbook::owner();
        $this->actingAs($this->teacher)->get(route('teachers.show', $textbook->public_id))->assertNotFound();
        $this->assertNull($textbook->profileUrl());
    }

    public function test_the_library_and_set_pages_link_to_the_owners_profile(): void
    {
        $this->colleague->update(['attribution_name' => '李大明']);
        $set = $this->vocabSet($this->colleague, '水果', [['pisang', '香蕉']]);
        $this->textbookSet();
        $url = route('teachers.show', $this->colleague->public_id);

        $this->actingAs($this->teacher)->get('/library')->assertInertia(fn (Assert $page) => $page
            ->has('sets.data', 2)
            ->where('sets.data.0.owner', '李大明')
            ->where('sets.data.0.owner_url', $url)
            ->where('sets.data.1.textbook', true)
            ->where('sets.data.1.owner_url', null));

        $this->actingAs($this->teacher)->get("/sets/{$set->id}")->assertInertia(fn (Assert $page) => $page
            ->where('set.authors', ['李大明'])
            ->where('set.owner', '李大明')
            ->where('set.owner_url', $url));
    }

    /**
     * 貢獻日曆：只算目前公開的題組，日期依台灣時間；公開的那一天另外算一次。
     */
    public function test_contributions_count_only_public_work_in_taiwan_time(): void
    {
        // UTC 10 月 5 日 17:30 是台灣的 10 月 6 日 01:30
        $this->travelTo(CarbonImmutable::parse('2026-10-05 17:30:00', 'UTC'));
        $private = $this->vocabSet($this->teacher, '私人', [['satu', '一']], public: false);
        $public = $this->vocabSet($this->teacher, '公開', [['dua', '二']]);
        $public->reviews()->create(['set_revision_id' => $public->current_revision_id, 'user_id' => $this->colleague->id, 'action' => 'approved']);

        $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));
        $this->write($public, [['dua', '二'], ['tiga', '三']], $this->teacher);

        $calendar = Contributions::calendar($this->teacher);
        $this->assertSame('2026-10-07', $calendar['to']);
        $this->assertSame('2025-10-05', $calendar['from']); // 52 週前那一週的星期日
        $this->assertSame([
            '2026-10-06' => ['revisions' => 1, 'published' => 1],
            '2026-10-07' => ['revisions' => 1, 'published' => 0],
        ], $calendar['days']);
        $this->assertSame(3, $calendar['total']);

        // 私人題組公開後，公開前的修改也算進來（和 GitHub 一樣）
        $private->update(['visibility' => 'public', 'review_status' => 'approved']);
        $this->assertSame(2, Contributions::calendar($this->teacher)['days']['2026-10-06']['revisions']);

        // 審核者修正公開題組：算審核者的貢獻，不算擁有者的
        $curator = User::factory()->create();
        $curator->assignRole('curator');
        $this->write($public, [['dua', '二'], ['tiga', '三（修）']], $curator);
        $this->assertSame(['2026-10-07' => ['revisions' => 1, 'published' => 0]], Contributions::calendar($curator)['days']);
        $this->assertSame(1, Contributions::calendar($this->teacher)['days']['2026-10-07']['revisions']);

        // 超過一年的不列；題組刪除後它的貢獻不再顯示
        Contribution::create(['user_id' => $this->teacher->id, 'set_id' => $public->id, 'date' => '2025-10-04', 'revisions' => 5]);
        $this->assertArrayNotHasKey('2025-10-04', Contributions::calendar($this->teacher)['days']);
        $public->delete();
        $this->assertSame(['2026-10-06' => ['revisions' => 1, 'published' => 0]], Contributions::calendar($this->teacher)['days']);

        // 停用的擁有者：題組不列在共備庫，貢獻也不算（頁面本身是 404）
        $this->teacher->forceFill(['disabled_at' => now()])->save();
        $this->assertSame([], Contributions::calendar($this->teacher->fresh())['days']);
    }

    public function test_the_stats_count_public_sets_entries_media_copies_and_activities(): void
    {
        $mine = $this->image($this->teacher);
        $mineInPrivate = $this->image($this->teacher);
        $mineInQuiz = $this->image($this->teacher);
        $theirs = $this->image($this->colleague);

        $public = $this->vocabSet($this->teacher, '公開', [['satu', '一', $mine->id], ['dua', '二']]);
        $this->vocabSet($this->teacher, '私人', [['tiga', '三', $mineInPrivate->id], ['empat', '四'], ['lima', '五']], public: false);
        $quiz = Set::factory()->quiz()->for($this->teacher, 'owner')->create(['language_code' => 'id', 'visibility' => 'public', 'review_status' => 'approved']);
        app(SetWriter::class)->write($quiz, ['entries' => [['question' => [
            'stem' => ['text' => '「謝謝」的印尼語是？', 'image_id' => $mineInQuiz->id],
            'options' => [['id' => 'a', 'text' => 'terima kasih', 'correct' => true], ['id' => 'b', 'text' => 'maaf', 'correct' => false]],
        ]]]], $this->teacher);
        // 複製同事的公開題組再公開：題組算自己的，同事的圖片算同事的
        $theirsSet = $this->vocabSet($this->colleague, '同事的', [['enam', '六', $theirs->id]]);
        app(SetCopier::class)->copy($theirsSet, $this->teacher)->update(['visibility' => 'public', 'review_status' => 'approved']);

        // 同事複製公開的題組並建立活動；自己的活動不算；刪掉的複製品不算
        $copy = app(SetCopier::class)->copy($public, $this->colleague);
        $copy->activities()->create(['game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->colleague->id]);
        $copy->activities()->create(['game_id' => 'match-up', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->colleague->id]);
        $public->activities()->create(['game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->teacher->id]);
        app(SetCopier::class)->copy($public, $this->colleague)->delete();

        $this->assertSame([
            'public_sets' => 3,
            'entries' => 4,
            'media' => 2,
            'copies' => 1,
            'activities' => 2,
        ], TeacherStats::for($this->teacher));

        // 同事：圖片用在自己與別人（複製後）的公開題組中，算一個；被老師複製一次
        $this->assertSame(['public_sets' => 1, 'entries' => 1, 'media' => 1, 'copies' => 1, 'activities' => 0], TeacherStats::for($this->colleague));

        // 下架後都不算
        $public->update(['visibility' => 'private']);
        $this->assertSame(['public_sets' => 2, 'entries' => 2, 'media' => 1, 'copies' => 0, 'activities' => 0], TeacherStats::for($this->teacher));
    }

    public function test_deleting_the_account_clears_the_profile_and_keeps_the_attribution_on_public_sets(): void
    {
        $this->teacher->update($this->profile());
        $public = $this->vocabSet($this->teacher, '公開', [['satu', '一']]);
        $url = route('teachers.show', $this->teacher->public_id);

        app(AccountDeletion::class)->delete($this->teacher);

        $user = $this->teacher->fresh();
        foreach (User::PROFILE_FIELDS as $field) {
            $this->assertNull($user->getAttribute($field), $field);
        }
        $this->assertSame('CC-BY-4.0', $user->defaultLicense());
        $this->assertSame([['name' => '王小明（臺北市○○國小）', 'url' => 'https://example.org/wang']], $public->fresh()?->effectiveAuthors());
        $this->actingAs($this->colleague)->get($url)->assertNotFound();
        $this->actingAs($this->colleague)->get('/library')->assertInertia(fn (Assert $page) => $page
            ->where('sets.data.0.owner', AccountDeletion::NAME)
            ->where('sets.data.0.owner_url', null));
    }
}
