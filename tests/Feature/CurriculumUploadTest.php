<?php

namespace Tests\Feature;

use App\Curriculum\CurriculumImages;
use App\Curriculum\CurriculumImporter;
use App\Curriculum\Textbook;
use App\Curriculum\VolumeFile;
use App\Filament\Pages\ImportCurriculum;
use App\Filament\Resources\CurriculumRefs\Pages\ListCurriculumRefs;
use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 在後台匯入教材：上傳詞彙的資料檔、依檔名批次上傳插圖（docs/SPEC.md 3.6、A-03）。
 * 詞彙取自印尼語第 2 冊（課文與詞彙.json 的格式）。
 */
class CurriculumUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->directory = sys_get_temp_dir().'/kq-curriculum-upload-'.uniqid();
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * 整理教材時的「課文與詞彙.json」，含課文。
     *
     * @return array<string, mixed>
     */
    private function bookTwo(): array
    {
        return [
            'book_title' => '新住民語文學習教材 印尼語第2冊',
            'source_pdf' => '印尼語第2冊_0212.pdf',
            'lessons' => [
                [
                    'lesson' => 1,
                    'title' => ['indonesian' => 'Sekolah Saya', 'chinese' => '我的校園'],
                    'text' => [['order' => 1, 'indonesian' => 'Siti: Di sekolah ada rumput hijau.', 'chinese' => '希娣：校園裡有綠草地。', 'page' => 5]],
                    'vocabulary' => [
                        ['indonesian' => 'merah', 'chinese' => '紅色', 'page' => 6],
                        ['indonesian' => 'kuning', 'chinese' => '黃色', 'page' => 6],
                        ['indonesian' => 'tidak ada', 'chinese' => '沒有', 'page' => 7],
                        ['indonesian' => 'ada', 'chinese' => '有', 'page' => 7],
                    ],
                ],
                [
                    'lesson' => 3,
                    'title' => ['indonesian' => 'Apa Kabar?', 'chinese' => '您好嗎？'],
                    'vocabulary' => [
                        ['indonesian' => 'kakek', 'chinese' => '外公', 'page' => 26],
                        ['indonesian' => 'apa kabar', 'chinese' => '你（您）好嗎', 'page' => 27],
                    ],
                ],
                [
                    'lesson' => 4,
                    'title' => ['indonesian' => 'Mari Makan', 'chinese' => '吃飯了'],
                    'vocabulary' => [
                        // 同一冊兩課都有的詞（測試用，教材中只在第 3 課）
                        ['indonesian' => 'kakek', 'chinese' => '外公', 'page' => 34],
                        ['indonesian' => 'nasi goreng', 'chinese' => '炒飯', 'page' => 34],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function importBookTwo(): void
    {
        app(CurriculumImporter::class)->importVolume(VolumeFile::fromUpload($this->encode($this->bookTwo()), 'id', 2), null);
    }

    private function png(string $name): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagepng($image, $path = "{$this->directory}/{$name}");

        return $path;
    }

    private function lesson(int $lesson): Set
    {
        return CurriculumRef::where(['language_code' => 'id', 'volume' => 2, 'lesson' => $lesson])->firstOrFail()->set()->firstOrFail();
    }

    private function imageOf(Set $set, string $text): ?string
    {
        $entry = $set->entries()->with('item.media')->get()->first(fn (SetEntry $entry) => $entry->item->text === $text);

        return $entry?->item->media->firstWhere('pivot.role', 'image')?->id;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_the_textbook_transcription_format_is_read_without_the_lesson_texts(): void
    {
        $volume = VolumeFile::fromUpload($this->encode($this->bookTwo()), 'id', 2);

        $this->assertSame(['id', 2, '新住民語文學習教材 印尼語第2冊'], [$volume['language'], $volume['volume'], $volume['textbook']]);
        $this->assertSame(Textbook::AUTHORS, $volume['authors']);
        $this->assertSame(['lesson' => 1, 'title_zh' => '我的校園', 'title_native' => 'Sekolah Saya'], array_intersect_key($volume['lessons'][0], array_flip(['lesson', 'title_zh', 'title_native'])));
        $this->assertSame(['text' => 'merah', 'translation_zh' => '紅色', 'page' => 6, 'image' => null], $volume['lessons'][0]['vocabulary'][0]);
        // 課文不讀（D-4）
        $this->assertStringNotContainsString('rumput', $this->encode($volume));
    }

    public function test_uploaded_files_for_another_volume_or_language_are_rejected(): void
    {
        $messages = function (callable $read): string {
            try {
                $read();
            } catch (ValidationException $e) {
                return implode("\n", array_merge(...array_values($e->errors())));
            }
            $this->fail('應該要擋下');
        };

        $this->assertStringContainsString('與選擇的印尼語第 1 冊不同', $messages(fn () => VolumeFile::fromUpload($this->encode($this->bookTwo()), 'id', 1)));
        $this->assertStringContainsString('與選擇的越南語第 2 冊不同', $messages(fn () => VolumeFile::fromUpload($this->encode($this->bookTwo()), 'vi', 2)));

        $volumeJson = ['language' => 'id', 'volume' => 1, 'lessons' => [['lesson' => 1, 'title_zh' => '我的名字', 'vocabulary' => [['text' => 'nama', 'translation_zh' => '名字']]]]];
        $this->assertStringContainsString('資料檔寫的是 id 第 1 冊', $messages(fn () => VolumeFile::fromUpload($this->encode($volumeJson), 'id', 2)));

        $broken = $this->bookTwo();
        $broken['lessons'][0]['vocabulary'][1] = ['chinese' => '黃色', 'page' => 6];
        $this->assertStringContainsString('第 1 課第 2 個詞：找不到目標語的欄位', $messages(fn () => VolumeFile::fromUpload($this->encode($broken), 'id', 2)));

        $this->assertStringContainsString('不是有效的 JSON', $messages(fn () => VolumeFile::fromUpload('{', 'id', 2)));
    }

    public function test_the_preview_does_not_write_anything(): void
    {
        $results = app(CurriculumImporter::class)->preview(VolumeFile::fromUpload($this->encode($this->bookTwo()), 'id', 2));

        $this->assertSame(['created', 'created', 'created'], array_column($results, 'result'));
        $this->assertSame(0, CurriculumRef::count());
        $this->assertSame(0, Set::count());
    }

    public function test_filenames_are_matched_to_words(): void
    {
        $this->assertSame('ibu_guru', CurriculumImages::fileKey('ibu guru'));
        $this->assertSame('tidak_apa_apa', CurriculumImages::fileKey('tidak apa-apa'));
        $this->assertSame('apa_kabar', CurriculumImages::fileKey('Apa Kabar?'));

        $this->importBookTwo();
        $words = CurriculumImages::words('id', 2);
        $this->assertSame([3, 4], $words['kakek']['lessons']);
        $this->assertSame(['tidak_ada'], CurriculumImages::candidates('Tidak_Ada.PNG', $words));
        $this->assertSame(['nasi_goreng'], CurriculumImages::candidates('nasi goreng.webp', $words));
        $this->assertSame([], CurriculumImages::candidates('pensil.png', $words));

        // 越南語的檔名常不打聲調：完全相同的優先，否則只在剛好一個詞符合時採用
        $vietnamese = array_flip(['quả_đu_đủ', 'ba', 'bà', 'bá']);
        $this->assertSame(['quả_đu_đủ'], CurriculumImages::candidates('qua_du_du.png', $vietnamese));
        $this->assertSame(['ba'], CurriculumImages::candidates('ba.png', $vietnamese));
        $this->assertSame(['ba', 'bà', 'bá'], CurriculumImages::candidates('bả.png', $vietnamese));
    }

    public function test_images_are_attached_by_word_and_shared_across_lessons(): void
    {
        $this->importBookTwo();
        $revision = $this->lesson(1)->current_revision_id;

        $result = app(CurriculumImages::class)->attach('id', 2, [
            'kakek' => $this->png('kakek.png'),
            'merah' => $this->png('merah.png'),
        ], Textbook::IMAGE_ATTRIBUTION);

        $this->assertSame(['words' => 2, 'lessons' => [1, 3, 4]], $result);
        $this->assertSame(2, Media::count());
        $this->assertNotNull($kakek = $this->imageOf($this->lesson(3), 'kakek'));
        $this->assertSame($kakek, $this->imageOf($this->lesson(4), 'kakek'));
        $this->assertSame('AI 生成', Media::findOrFail($kakek)->source);

        // 新版本記在教材帳號名下，題目 ID 不變
        $set = $this->lesson(1);
        $this->assertNotSame($revision, $set->current_revision_id);
        $this->assertSame(Textbook::owner()->id, $set->currentRevision?->created_by);
        $this->assertSame('merah', $set->currentRevision?->content()->entries[0]->item->text);
        $this->assertNotNull($set->currentRevision?->content()->entries[0]->item->image);

        // 已經有圖的詞預設不動
        $this->assertSame(['words' => 0, 'lessons' => []], app(CurriculumImages::class)->attach('id', 2, ['merah' => $this->png('merah2.png')], Textbook::IMAGE_ATTRIBUTION));
        $merah = $this->imageOf($this->lesson(1), 'merah');
        app(CurriculumImages::class)->attach('id', 2, ['merah' => $this->png('merah2.png')], Textbook::IMAGE_ATTRIBUTION, replace: true);
        $this->assertNotSame($merah, $this->imageOf($this->lesson(1), 'merah'));
    }

    public function test_reimporting_words_keeps_uploaded_images(): void
    {
        $this->importBookTwo();
        app(CurriculumImages::class)->attach('id', 2, ['merah' => $this->png('merah.png')], Textbook::IMAGE_ATTRIBUTION);
        $merah = $this->imageOf($this->lesson(1), 'merah');

        $data = $this->bookTwo();
        $data['lessons'][0]['vocabulary'][0]['chinese'] = '紅';
        $results = app(CurriculumImporter::class)->importVolume(VolumeFile::fromUpload($this->encode($data), 'id', 2), null);

        // 上傳插圖不算網站上的修正，重新匯入照常更新
        $this->assertSame(['updated', 'unchanged', 'unchanged'], array_column($results, 'result'));
        $this->assertSame($merah, $this->imageOf($this->lesson(1), 'merah'));
        $this->assertSame('紅', $this->lesson(1)->entries()->with('item')->firstOrFail()->item->translation_zh);
    }

    public function test_attaching_images_keeps_a_lesson_marked_as_corrected_on_the_website(): void
    {
        $this->importBookTwo();
        $set = $this->lesson(1);
        $curator = User::factory()->create();
        $curator->assignRole('curator');
        $curator->reviewLanguages()->attach('id');

        $entries = $set->entries()->with('item')->get();
        $this->actingAs($curator)->put("/sets/{$set->id}", [
            'title' => $set->title,
            'language_code' => 'id',
            'license' => 'CC-BY-4.0',
            'curriculum_ref_ids' => $set->curriculumRefs->modelKeys(),
            'faces' => Textbook::FACES,
            'entries' => $entries->map(fn (SetEntry $entry, int $i) => ['id' => $entry->id, 'item' => [
                'text' => $entry->item->text,
                'translation_zh' => $i === 0 ? '紅' : $entry->item->translation_zh,
            ]])->all(),
        ])->assertRedirect();

        app(CurriculumImages::class)->attach('id', 2, ['merah' => $this->png('merah.png')], Textbook::IMAGE_ATTRIBUTION);
        $this->assertNotNull($this->imageOf($set, 'merah'));

        $results = app(CurriculumImporter::class)->importVolume(VolumeFile::fromUpload($this->encode($this->bookTwo()), 'id', 2), null);
        $this->assertSame('skipped', $results[0]['result']);
        $this->assertSame('紅', $set->entries()->with('item')->firstOrFail()->item->translation_zh);
    }

    public function test_only_admins_can_use_the_import_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/import-curriculum')->assertOk()->assertSee('匯入詞彙')->assertSee('上傳插圖');

        foreach (['curator', 'teacher'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->get('/admin/import-curriculum')->assertForbidden();
        }
    }

    public function test_an_admin_previews_and_imports_words_then_uploads_images(): void
    {
        $this->actingAs($this->admin());

        $page = Livewire::test(ImportCurriculum::class)
            ->set('words.volume', 2)
            ->set('words.file', UploadedFile::fake()->createWithContent('印尼語第2冊_課文與詞彙.json', $this->encode($this->bookTwo())))
            ->call('previewWords')
            ->assertHasNoErrors()
            ->assertSet('wordsPreview.summary', '印尼語第 2 冊（新住民語文學習教材 印尼語第2冊）：3 課、8 個詞')
            ->assertSet('wordsPreview.lessons.0.result', 'created');
        $this->assertSame(0, CurriculumRef::count());

        $page->call('importWords')->assertSet('wordsPreview', null);
        $this->assertSame([1, 3, 4], CurriculumRef::where('volume', 2)->orderBy('lesson')->pluck('lesson')->all());

        // 選了冊就先列出缺插圖的詞與對應的檔名
        $page->set('images.volume', 2)
            ->assertSee('這一冊還沒有插圖的詞（7 個），括號中是對應的檔名：merah（merah）、kuning（kuning）、tidak ada（tidak_ada）')
            ->set('images.files', [
                UploadedFile::fake()->image('Merah.png'),
                UploadedFile::fake()->image('kakek.jpg'),
                UploadedFile::fake()->image('pensil.png'),
            ])
            ->call('matchImages')
            ->assertHasNoErrors();

        $matches = array_values($page->get('images.matches'));
        $this->assertSame(['Merah.png' => 'merah', 'kakek.jpg' => 'kakek', 'pensil.png' => null], array_column($matches, 'word', 'name'));

        // 配不到的圖可以手動選詞
        $pensil = array_search('pensil.png', array_column($page->get('images.matches'), 'name', null), true);
        $key = array_keys($page->get('images.matches'))[$pensil];
        $page->set("images.matches.{$key}.word", 'ada')->call('attachImages');

        $this->assertNotNull($this->imageOf($this->lesson(1), 'merah'));
        $this->assertNotNull($this->imageOf($this->lesson(1), 'ada'));
        $this->assertSame($this->imageOf($this->lesson(3), 'kakek'), $this->imageOf($this->lesson(4), 'kakek'));
        $this->assertSame(3, Media::count());
        $this->assertSame([], $page->get('images.matches'));
    }

    public function test_the_curriculum_table_lists_words_without_images(): void
    {
        $this->importBookTwo();
        app(CurriculumImages::class)->attach('id', 2, ['kakek' => $this->png('kakek.png')], Textbook::IMAGE_ATTRIBUTION);
        $refs = CurriculumRef::where('volume', 2)->orderBy('lesson')->get();
        $empty = CurriculumRef::create(['language_code' => 'id', 'volume' => 2, 'lesson' => 9, 'title_zh' => '還沒有匯入']);

        $this->actingAs($this->admin());
        Livewire::test(ListCurriculumRefs::class)
            ->assertTableColumnStateSet('images', '缺 4 個：merah、kuning、tidak ada、ada', $refs[0])
            ->assertTableColumnStateSet('images', '缺 1 個：apa kabar', $refs[1])
            ->assertTableColumnStateSet('images', null, $empty)
            ->filterTable('missing_images')
            ->assertCanSeeTableRecords([$refs[0], $refs[1]])
            ->assertCanNotSeeTableRecords([$empty]);

        app(CurriculumImages::class)->attach('id', 2, ['apa_kabar' => $this->png('apa_kabar.png')], Textbook::IMAGE_ATTRIBUTION);
        Livewire::test(ListCurriculumRefs::class)
            ->assertTableColumnStateSet('images', '齊全', $refs[1])
            ->filterTable('missing_images')
            ->assertCanNotSeeTableRecords([$refs[1]]);
    }

    public function test_the_import_page_shows_file_errors(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ImportCurriculum::class)
            ->set('words.volume', 1)
            ->set('words.file', UploadedFile::fake()->createWithContent('印尼語第2冊_課文與詞彙.json', $this->encode($this->bookTwo())))
            ->call('previewWords')
            ->assertHasErrors(['words.file'])
            ->assertSet('wordsPreview', null);
    }
}
