<?php

namespace Tests\Feature;

use App\Curriculum\CurriculumImporter;
use App\Curriculum\Textbook;
use App\Models\Activity;
use App\Models\CurriculumRef;
use App\Models\Item;
use App\Models\Language;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 教材：匯入課名與詞彙、教材題組的權限、用教材建立活動與挑詞建立題組（docs/SPEC.md 3.6、T-18）。
 */
class CurriculumTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->directory = sys_get_temp_dir().'/kq-curriculum-'.uniqid();
        File::ensureDirectoryExists("{$this->directory}/images");
        foreach (['ayah', 'ibu', 'kakak', 'selamat_pagi', 'kakek'] as $name) {
            $image = imagecreatetruecolor(40, 40);
            imagepng($image, "{$this->directory}/images/{$name}.png");
        }
        $this->writeVolume($this->volume());

        $this->teacher = User::factory()->create(['name' => '王老師']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function volume(): array
    {
        return [
            'language' => 'id',
            'volume' => 1,
            'textbook' => '新住民語文學習教材 印尼語第1冊',
            'authors' => [['name' => 'Kancil Quiz']],
            'license' => 'CC-BY-4.0',
            'images' => ['authors' => [['name' => 'Kancil Quiz']], 'license' => 'CC-BY-4.0', 'source' => 'AI 生成'],
            'lessons' => [
                [
                    'lesson' => 3,
                    'title_native' => 'Keluarga Saya',
                    'title_zh' => '我的家人',
                    'text' => [['indonesian' => 'Siti: Saya sayang Ibu.', 'chinese' => '希娣：我愛媽媽。']],
                    'vocabulary' => [
                        ['text' => 'ayah', 'translation_zh' => '爸爸', 'page' => 26, 'image' => 'images/ayah.png'],
                        ['text' => 'ibu', 'translation_zh' => '媽媽', 'page' => 26, 'image' => 'images/ibu.png'],
                        ['text' => 'kakak', 'translation_zh' => '哥哥/姐姐', 'page' => 26, 'image' => 'images/kakak.png'],
                    ],
                ],
                [
                    'lesson' => 4,
                    'title_native' => 'Selamat Pagi, Kakek',
                    'title_zh' => '爺爺早安',
                    'vocabulary' => [
                        ['text' => 'selamat pagi', 'translation_zh' => '早安', 'page' => 34, 'image' => 'images/selamat_pagi.png'],
                        ['text' => 'kakek', 'translation_zh' => '爺爺', 'page' => 35, 'image' => 'images/kakek.png'],
                        ['text' => 'ibu', 'translation_zh' => '媽媽', 'image' => 'images/ibu.png'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $volume
     */
    private function writeVolume(array $volume): void
    {
        file_put_contents("{$this->directory}/volume.json", json_encode($volume, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{lesson: int, title: string, words: int, result: string}>
     */
    private function import(bool $force = false): array
    {
        return app(CurriculumImporter::class)->import($this->directory, $force);
    }

    private function lesson(int $lesson): Set
    {
        return CurriculumRef::where(['language_code' => 'id', 'volume' => 1, 'lesson' => $lesson])->firstOrFail()->set()->firstOrFail();
    }

    private function curator(string $language): User
    {
        $curator = User::factory()->create(['name' => '陳審核']);
        $curator->assignRole('curator');
        $curator->reviewLanguages()->attach($language);

        return $curator;
    }

    public function test_importing_a_volume_creates_lessons_and_public_textbook_sets(): void
    {
        $results = $this->import();
        $this->assertSame(['created', 'created'], array_column($results, 'result'));

        $ref = CurriculumRef::where(['language_code' => 'id', 'volume' => 1, 'lesson' => 3])->firstOrFail();
        $this->assertSame('我的家人', $ref->title_zh);
        $this->assertSame('Keluarga Saya', $ref->title_native);

        $set = $this->lesson(3);
        $this->assertSame(Textbook::OWNER_EMAIL, $set->owner->email);
        $this->assertNull($set->owner->email_verified_at); // 教材帳號不能登入
        $this->assertTrue($set->isTextbook());
        $this->assertSame('第 1 冊第 3 課：Keluarga Saya 我的家人', $set->title);
        $this->assertSame(['public', 'approved'], [$set->visibility, $set->review_status]);
        $this->assertSame([3], $set->curriculumRefs->pluck('lesson')->all());

        $content = $set->currentRevision()->firstOrFail()->content();
        $this->assertSame([['name' => 'Kancil Quiz']], json_decode((string) json_encode($content->authors), true));
        $this->assertSame(Textbook::FACES, json_decode((string) json_encode($content->faces), true));
        $this->assertSame(['ayah', 'ibu', 'kakak'], array_map(fn ($entry) => $entry->item->text, $content->entries));
        $item = $content->entries[0]->item;
        $this->assertSame('《新住民語文學習教材 印尼語第1冊》第 3 課，課本第 26 頁', $item->source);
        $this->assertSame('CC-BY-4.0', $item->license);
        $this->assertSame('AI 生成', $item->image->source);
        // 課文不匯入（D-4）
        $this->assertStringNotContainsString('Siti', (string) json_encode($content, JSON_UNESCAPED_UNICODE));

        // 兩課都有的詞共用同一個圖檔，只轉檔一次
        $this->assertSame(5, Media::count());
        $ibu = Item::where('text', 'ibu')->with('media')->get();
        $this->assertCount(2, $ibu);
        $this->assertSame($ibu[0]->media->first()?->id, $ibu[1]->media->first()?->id);
    }

    public function test_reimporting_keeps_entry_ids_and_only_records_changes(): void
    {
        $this->import();
        $set = $this->lesson(3);
        $revision = $set->current_revision_id;
        $ayah = $set->entries()->with('item.media')->firstOrFail();

        $this->assertSame(['unchanged', 'unchanged'], array_column($this->import(), 'result'));
        $this->assertSame($revision, $set->refresh()->current_revision_id);

        $volume = $this->volume();
        $volume['lessons'][0]['vocabulary'][1]['translation_zh'] = '母親';
        array_pop($volume['lessons'][0]['vocabulary']);
        $this->writeVolume($volume);

        $this->assertSame(['updated', 'unchanged'], array_column($this->import(), 'result'));
        $set->refresh();
        $this->assertNotSame($revision, $set->current_revision_id);
        $entries = $set->entries()->with('item.media')->get();
        $this->assertSame(['ayah', 'ibu'], $entries->pluck('item.text')->all());
        $this->assertSame(['爸爸', '母親'], $entries->pluck('item.translation_zh')->all());
        // 題目 ID 與圖檔不變，作答紀錄仍對得上（docs/SPEC.md 3.3）
        $this->assertSame($ayah->id, $entries[0]->id);
        $this->assertSame($ayah->item->media->first()?->id, $entries[0]->item->media->first()?->id);
    }

    public function test_reimporting_updates_the_image_credits_without_new_files(): void
    {
        $this->import();
        $ids = Media::orderBy('id')->pluck('id')->all();

        $volume = $this->volume();
        $volume['images']['source'] = 'AI 生成，人工修圖';
        $this->writeVolume($volume);

        $this->assertSame(['updated', 'updated'], array_column($this->import(), 'result'));
        $this->assertSame($ids, Media::orderBy('id')->pluck('id')->all());
        $this->assertSame(['AI 生成，人工修圖'], Media::distinct()->pluck('source')->all());
        $this->assertSame('AI 生成，人工修圖', $this->lesson(4)->currentRevision()->firstOrFail()->content()->entries[0]->item->image->source);
    }

    public function test_sets_corrected_on_the_website_are_not_overwritten_unless_forced(): void
    {
        $this->import();
        $set = $this->lesson(3);
        $curator = $this->curator('id');

        $this->actingAs($curator)->get("/sets/{$set->id}/edit")->assertOk();
        $entries = $set->entries()->with('item.media')->get();
        $this->actingAs($curator)->put("/sets/{$set->id}", [
            'title' => $set->title,
            'language_code' => 'id',
            'license' => 'CC-BY-4.0',
            'curriculum_ref_ids' => $set->curriculumRefs->modelKeys(),
            'faces' => Textbook::FACES,
            'entries' => $entries->map(fn (SetEntry $entry, int $i) => ['id' => $entry->id, 'item' => [
                'text' => $entry->item->text,
                'translation_zh' => $i === 2 ? '哥哥、姐姐' : $entry->item->translation_zh,
                'image_id' => $entry->item->media->first()?->id,
            ]])->all(),
        ])->assertRedirect();
        $this->assertSame('哥哥、姐姐', $set->entries()->with('item')->get()[2]->item->translation_zh);

        $this->assertSame(['skipped', 'unchanged'], array_column($this->import(), 'result'));
        $this->assertSame('哥哥、姐姐', $set->entries()->with('item')->get()[2]->item->translation_zh);

        $this->assertSame('updated', $this->import(force: true)[0]['result']);
        $this->assertSame('哥哥/姐姐', $set->entries()->with('item')->get()[2]->item->translation_zh);
    }

    public function test_invalid_volume_files_are_rejected(): void
    {
        $volume = $this->volume();
        $volume['lessons'][0]['vocabulary'][] = ['text' => 'Ayah ', 'translation_zh' => '父親'];
        $volume['lessons'][1]['vocabulary'][0]['image'] = 'images/missing.png';
        $this->writeVolume($volume);

        try {
            $this->import();
            $this->fail('應該要擋下不合法的資料檔');
        } catch (ValidationException $e) {
            $messages = implode("\n", array_merge(...array_values($e->errors())));
            $this->assertStringContainsString('第 3 課的「ayah」重複', $messages);
            $this->assertStringContainsString('第 4 課找不到圖檔 images/missing.png', $messages);
        }
        $this->assertSame(0, Set::count());

        $this->artisan('kancil:import-curriculum', ['directory' => $this->directory])->assertFailed();
    }

    public function test_any_teacher_can_create_activities_from_textbook_sets_but_cannot_manage_them(): void
    {
        $this->import();
        $set = $this->lesson(3);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($this->teacher)->get("/sets/{$set->id}")->assertInertia(fn (Assert $page) => $page
            ->component('sets/Show')
            ->where('can.activity', true)
            ->where('can.copy', true)
            ->where('can.manage', false)
            ->where('can.edit', false)
            ->where('set.textbook_url', route('curriculum.lesson', ['language' => 'id', 'volume' => 1, 'lesson' => 3]))
            ->where('set.authors', ['Kancil Quiz']));

        $this->actingAs($this->teacher)->get("/sets/{$set->id}/activities/create")->assertOk();
        $this->actingAs($this->teacher)->post("/sets/{$set->id}/activities", ['game_id' => 'quiz'])->assertRedirect();
        $activity = Activity::firstOrFail();
        $this->assertSame($this->teacher->id, $activity->owner_id);

        $this->actingAs($this->teacher)->get("/sets/{$set->id}/edit")->assertForbidden();
        $this->actingAs($this->teacher)->post("/sets/{$set->id}/share")->assertForbidden();
        $this->actingAs($this->teacher)->post("/sets/{$set->id}/publication")->assertForbidden();
        // 刪除題組會一併刪除活動，教材題組上有其他老師的活動，連管理員也不能刪
        $this->actingAs($admin)->delete("/sets/{$set->id}")->assertForbidden();
        $this->actingAs($this->curator('id'))->post("/sets/{$set->id}/review", ['decision' => 'unpublish', 'note' => '下架'])->assertForbidden();
        $this->assertTrue($set->refresh()->isPublic());

        // 刪除活動後回到那一課
        $this->actingAs($this->teacher)->delete("/activities/{$activity->id}")
            ->assertRedirect(route('curriculum.lesson', ['language' => 'id', 'volume' => 1, 'lesson' => 3]));
    }

    public function test_teachers_only_see_their_own_activities_on_a_textbook_set(): void
    {
        $this->import();
        $set = $this->lesson(3);
        $colleague = User::factory()->create(['name' => '李老師']);
        $mine = $set->activities()->create(['game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->teacher->id]);
        $set->activities()->create(['game_id' => 'maze-chase', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->teacher->id]);
        $theirs = $set->activities()->create(['game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $colleague->id]);

        $this->actingAs($this->teacher)->get("/activities/{$mine->id}")->assertInertia(fn (Assert $page) => $page
            ->has('siblings', 1)
            ->where('siblings.0.game_id', 'maze-chase')
            ->where('set.url', route('curriculum.lesson', ['language' => 'id', 'volume' => 1, 'lesson' => 3])));
        $this->actingAs($this->teacher)->get("/activities/{$theirs->id}")->assertForbidden();

        $this->actingAs($colleague)->get('/curriculum/id/1/3')->assertInertia(fn (Assert $page) => $page
            ->has('activities', 1)
            ->where('activities.0.id', $theirs->id));
    }

    public function test_a_teacher_creates_a_vocab_set_from_textbook_words(): void
    {
        $this->import();
        $family = $this->lesson(3)->entries()->with('item.media')->get();
        $greeting = $this->lesson(4)->entries()->with('item')->firstOrFail();

        $this->actingAs($this->teacher)->get('/sets/create?language=id&volume=1&lesson=3')->assertInertia(fn (Assert $page) => $page
            ->component('sets/Create')
            ->where('language', 'id')
            ->where('preset', ['volume' => 1, 'lesson' => 3])
            ->has('textbook', 2)
            ->has('textbook.0.words', 3)
            ->where('textbook.0.words.0.text', 'ayah'));

        $this->actingAs($this->teacher)->post('/sets', [
            'kind' => 'vocab',
            'title' => '家人與打招呼',
            'language_code' => 'id',
            'license' => 'CC-BY-4.0',
            'textbook_entries' => [$family[0]->id, $greeting->id, $family[1]->id],
        ])->assertRedirect();

        $set = $this->teacher->sets()->with('curriculumRefs')->firstOrFail();
        $this->assertNull($set->forked_from_id);
        $this->assertSame([3, 4], $set->curriculumRefs->pluck('lesson')->all());
        $this->assertSame(Textbook::FACES, $set->faces);
        $this->assertSame([['name' => 'Kancil Quiz']], $set->authors);

        $entries = $set->entries()->with('item.media')->get();
        $this->assertSame(['ayah', 'selamat pagi', 'ibu'], $entries->pluck('item.text')->all());
        $this->assertSame($family[0]->item_id, $entries[0]->item->forked_from_id);
        $this->assertSame($this->teacher->id, $entries[0]->item->owner_id);
        // 媒體共用，不複製檔案
        $this->assertSame($family[0]->item->media->first()?->id, $entries[0]->item->media->first()?->id);
        $this->assertSame(1, $set->revisions()->count());

        // 只挑一課時，複製來源是那一課的教材題組
        $this->actingAs($this->teacher)->post('/sets', [
            'kind' => 'vocab', 'title' => '家人', 'language_code' => 'id', 'license' => 'CC-BY-4.0',
            'textbook_entries' => [$family[2]->id],
        ])->assertRedirect();
        $this->assertSame($this->lesson(3)->id, $this->teacher->sets()->where('title', '家人')->value('forked_from_id'));
    }

    public function test_only_textbook_words_of_the_same_language_can_be_picked(): void
    {
        $this->import();
        $word = $this->lesson(3)->entries()->firstOrFail();
        $private = Set::factory()->for($this->teacher, 'owner')->create(['language_code' => 'id']);
        $privateEntry = $private->entries()->create(['position' => 0, 'item_id' => Item::create([
            'language_code' => 'id', 'text' => 'nenek', 'translation_zh' => '奶奶', 'owner_id' => $this->teacher->id,
        ])->id]);
        $details = ['title' => '測試', 'license' => 'CC-BY-4.0'];

        foreach ([
            ['kind' => 'vocab', 'language_code' => 'id', 'textbook_entries' => [$privateEntry->id]],
            ['kind' => 'vocab', 'language_code' => 'vi', 'textbook_entries' => [$word->id]],
            ['kind' => 'quiz', 'language_code' => 'id', 'textbook_entries' => [$word->id]],
        ] as $input) {
            $this->actingAs($this->teacher)->post('/sets', [...$details, ...$input])->assertSessionHasErrors('textbook_entries');
        }
        $this->assertSame(1, $this->teacher->sets()->count());
    }

    public function test_teachers_browse_the_textbook_by_language_volume_and_lesson(): void
    {
        $this->import();
        $colleague = User::factory()->create(['name' => '李老師']);
        $copy = Set::factory()->for($colleague, 'owner')->create(['language_code' => 'id', 'title' => '家人改編', 'visibility' => 'public', 'review_status' => 'approved']);
        $copy->curriculumRefs()->attach(CurriculumRef::where('lesson', 3)->value('id'));
        $mine = Set::factory()->for($this->teacher, 'owner')->create(['language_code' => 'id', 'title' => '我的家人練習']);
        $mine->curriculumRefs()->attach(CurriculumRef::where('lesson', 3)->value('id'));

        $this->actingAs($this->teacher)->get('/curriculum')->assertInertia(fn (Assert $page) => $page
            ->component('curriculum/Index')
            ->where('language', 'id')
            ->has('lessons', 2)
            ->where('lessons.0.title_native', 'Keluarga Saya')
            ->has('lessons.0.words', 3)
            ->where('lessons.0.url', route('curriculum.lesson', ['language' => 'id', 'volume' => 1, 'lesson' => 3])));

        $this->actingAs($this->teacher)->get('/curriculum/id/1/3')->assertInertia(fn (Assert $page) => $page
            ->component('curriculum/Lesson')
            ->where('lesson.title_zh', '我的家人')
            ->has('words', 3)
            ->where('words.0.item.text', 'ayah')
            ->where('set.can.activity', true)
            ->where('set.can.edit', false)
            ->where('imageCredits', ['Kancil Quiz，AI 生成（CC-BY-4.0）'])
            ->has('shared', 1)
            ->where('shared.0.title', '家人改編')
            ->has('mySets', 1)
            ->where('mySets.0.title', '我的家人練習'));

        $this->actingAs($this->teacher)->get('/curriculum/id/1/9')->assertNotFound();
        Language::whereKey('id')->update(['enabled' => false]);
        $this->actingAs($this->teacher)->get('/curriculum/id/1/3')->assertNotFound();
    }

    public function test_the_library_marks_textbook_sets(): void
    {
        $this->import();

        $this->actingAs($this->teacher)->get('/library?language=id&volume=1&lesson=4')->assertInertia(fn (Assert $page) => $page
            ->has('sets.data', 1)
            ->where('sets.data.0.textbook', true)
            ->where('sets.data.0.owner', Textbook::OWNER_NAME)
            ->where('curriculumRefs.1.title_native', 'Selamat Pagi, Kakek'));
    }
}
