<?php

namespace Tests\Feature;

use App\Corpus\SetCopier;
use App\Corpus\SetWriter;
use App\Models\Activity;
use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\Set;
use App\Models\User;
use App\Support\KancilFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeacherFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
    }

    private function createSet(string $kind = 'vocab'): Set
    {
        $this->actingAs($this->teacher)->post('/sets', [
            'kind' => $kind,
            'title' => '水果',
            'language_code' => 'vi',
            'license' => 'CC-BY-4.0',
        ])->assertRedirect();

        return Set::latest('created_at')->firstOrFail();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $words
     * @return array<string, mixed>
     */
    private function vocabPayload(array $words): array
    {
        return [
            'title' => '水果',
            'language_code' => 'vi',
            'license' => 'CC-BY-4.0',
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => array_map(fn ($word) => ['id' => null, 'item' => [
                'text' => $word[0], 'romanization' => null, 'translation_zh' => $word[1], 'audio_ids' => [], 'image_id' => null,
            ]], $words),
        ];
    }

    public function test_a_teacher_creates_a_vocab_set_by_pasting_words(): void
    {
        $set = $this->createSet();
        $this->assertSame(1, $set->currentRevision->number);

        // 越南文輸入法可能送出 NFD，伺服器以 NFC 儲存（docs/SPEC.md M1 驗收 5）
        $nfd = \Normalizer::normalize('quả chuối', \Normalizer::FORM_D);
        $this->put("/sets/{$set->id}", $this->vocabPayload([[$nfd, '香蕉'], ['quả táo', '蘋果']]))
            ->assertRedirect("/sets/{$set->id}/edit")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('items', ['text' => 'quả chuối', 'translation_zh' => '香蕉']);
        $this->assertSame(2, $set->fresh()->currentRevision->number);

        $this->get("/sets/{$set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->component('sets/Edit')
            ->has('entries', 2)
            ->where('entries.0.item.text', 'quả chuối'));
    }

    public function test_sets_have_curriculum_refs_and_tags(): void
    {
        $set = $this->createSet();
        $lesson = CurriculumRef::create(['language_code' => 'vi', 'volume' => 3, 'lesson' => 2, 'title_zh' => '水果']);
        $other = CurriculumRef::create(['language_code' => 'id', 'volume' => 1, 'lesson' => 1]);

        $this->put("/sets/{$set->id}", [
            ...$this->vocabPayload([['quả chuối', '香蕉']]),
            'tags' => ['水果', ' 食物 ', '水果'],
            'curriculum_ref_ids' => [$other->id],
        ])->assertSessionHasErrors(['tags.2', 'curriculum_ref_ids.0']);

        $this->put("/sets/{$set->id}", [
            ...$this->vocabPayload([['quả chuối', '香蕉']]),
            'tags' => ['水果', ' 食物 '],
            'curriculum_ref_ids' => [$lesson->id],
        ])->assertSessionHasNoErrors();

        // 交換格式的題組層級欄位（docs/SPEC.md 6.3）
        $content = $set->fresh()?->currentRevision?->content();
        $this->assertNotNull($content);
        $this->assertSame([], app(KancilFormat::class)->setErrors($content));
        $this->assertEquals([(object) ['volume' => 3, 'lesson' => 2]], $content->curriculum);
        $this->assertSame(['水果', '食物'], $content->tags);

        $this->get("/sets/{$set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('set.tags', ['水果', '食物'])
            ->where('set.curriculum_ref_ids', [$lesson->id])
            ->has('curriculumRefs', 2));
    }

    public function test_quiz_questions_need_exactly_one_correct_option(): void
    {
        $set = $this->createSet('quiz');

        $this->put("/sets/{$set->id}", [
            'title' => '打招呼', 'language_code' => 'vi', 'license' => 'CC-BY-4.0',
            'entries' => [['id' => null, 'question' => [
                'stem' => ['text' => '「謝謝」的越南語是？'],
                'options' => [
                    ['id' => 'a', 'text' => 'Cảm ơn', 'correct' => true],
                    ['id' => 'b', 'text' => 'Xin chào', 'correct' => true],
                ],
            ]]],
        ])->assertSessionHasErrors(['entries.0.question.options' => '第 1 題要恰好標示一個正解']);
    }

    public function test_a_quiz_set_is_saved_in_the_exchange_format(): void
    {
        $set = $this->createSet('quiz');

        $this->put("/sets/{$set->id}", [
            'title' => '打招呼', 'language_code' => 'vi', 'license' => 'CC-BY-4.0',
            'entries' => [['id' => null, 'question' => [
                'stem' => ['text' => '「謝謝」的越南語是？', 'audio_id' => null, 'image_id' => null],
                'options' => [
                    ['id' => 'a', 'text' => 'Cảm ơn', 'image_id' => null, 'correct' => true],
                    ['id' => 'b', 'text' => 'Xin chào', 'image_id' => null, 'correct' => false],
                ],
            ]]],
        ])->assertSessionHasNoErrors();

        $content = $set->fresh()->currentRevision->content();
        $this->assertSame('「謝謝」的越南語是？', $content->entries[0]->question->stem->text);
        $this->assertTrue($content->entries[0]->question->options[0]->correct);
    }

    public function test_teachers_cannot_edit_other_teachers_sets(): void
    {
        $set = $this->createSet();
        $other = User::factory()->create();

        $this->actingAs($other)->get("/sets/{$set->id}/edit")->assertForbidden();
        $this->actingAs($other)->put("/sets/{$set->id}", $this->vocabPayload([['a', 'b']]))->assertForbidden();
    }

    public function test_media_from_other_teachers_cannot_be_attached(): void
    {
        $set = $this->createSet();
        $media = Media::create([
            'kind' => 'image', 'path' => 'media/x.webp', 'mime' => 'image/webp', 'bytes' => 1,
            'uploaded_by' => User::factory()->create()->id,
        ]);
        $payload = $this->vocabPayload([['quả chuối', '香蕉']]);
        $payload['entries'][0]['item']['image_id'] = $media->id;

        $this->put("/sets/{$set->id}", $payload)->assertSessionHasErrors('entries');
    }

    public function test_uploaded_images_are_converted_to_webp(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->teacher)->post('/media', [
            'kind' => 'image',
            'file' => UploadedFile::fake()->image('banana.png', 2000, 1000),
            'rights' => '1',
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = Media::findOrFail($response->json('id'));
        $this->assertSame('image/webp', $media->mime);
        $this->assertSame([1024, 512], [$media->width, $media->height]);
        Storage::disk('public')->assertExists([$media->path, $media->thumbnail_path]);
        $this->assertSame([['name' => $this->teacher->name]], $media->authors);
    }

    public function test_each_teacher_has_an_upload_quota(): void
    {
        Storage::fake('public');
        config(['kancil.upload_quota_mb' => 1]);
        $upload = fn (User $user) => $this->actingAs($user)->post('/media', [
            'kind' => 'image',
            'file' => UploadedFile::fake()->image('banana.png', 40, 40),
            'rights' => '1',
        ], ['Accept' => 'application/json']);

        // 以轉檔後的大小計算，到達上限之後就不能再上傳；上傳的回應附上最新的用量，編輯頁用來更新顯示
        Media::create(['kind' => 'image', 'path' => 'media/a.webp', 'mime' => 'image/webp', 'bytes' => 1024 * 1024 - 1, 'uploaded_by' => $this->teacher->id]);
        $response = $upload($this->teacher)->assertCreated()->assertJsonPath('quota.limit_bytes', 1024 * 1024);
        $this->assertGreaterThanOrEqual(1024 * 1024, $response->json('quota.used_bytes'));
        $this->assertSame(Media::where('uploaded_by', $this->teacher->id)->sum('bytes'), $response->json('quota.used_bytes'));
        $upload($this->teacher)->assertUnprocessable()->assertJsonValidationErrors(['file' => '你上傳的檔案已經到達上限（已上傳 1 MB／1 MB），請聯絡網站管理員。']);

        // 管理員可以為個別老師調整上限（users.upload_quota_mb）；0 表示不能再上傳
        $this->teacher->forceFill(['upload_quota_mb' => 2])->save();
        $upload($this->teacher)->assertCreated()->assertJsonPath('quota.limit_bytes', 2 * 1024 * 1024);
        $this->teacher->forceFill(['upload_quota_mb' => 0])->save();
        $upload($this->teacher)->assertUnprocessable()->assertJsonValidationErrors(['file' => '你上傳的檔案已經到達上限（已上傳 1 MB／0 MB），請聯絡網站管理員。']);

        // 管理員不受限制
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Media::create(['kind' => 'image', 'path' => 'media/b.webp', 'mime' => 'image/webp', 'bytes' => 2 * 1024 * 1024, 'uploaded_by' => $admin->id]);
        $upload($admin)->assertCreated()->assertJsonPath('quota.limit_bytes', null);
    }

    /**
     * 編輯頁顯示「已上傳多少／上限」（docs/SPEC.md 第 9 節），算法與上傳時的檢查相同（App\Media\UploadQuota）。
     */
    public function test_the_editor_shows_how_much_the_teacher_has_uploaded(): void
    {
        config(['kancil.upload_quota_mb' => 200]);
        $set = $this->createSet();
        Media::create(['kind' => 'image', 'path' => 'media/a.webp', 'mime' => 'image/webp', 'bytes' => 37 * 1024 * 1024, 'uploaded_by' => $this->teacher->id]);

        $this->get("/sets/{$set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('uploadQuota', ['used_bytes' => 37 * 1024 * 1024, 'limit_bytes' => 200 * 1024 * 1024]));

        // 個別調整的上限
        $this->teacher->forceFill(['upload_quota_mb' => 50])->save();
        $this->get("/sets/{$set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('uploadQuota.limit_bytes', 50 * 1024 * 1024));

        // 管理員不限總量
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->post('/sets', ['kind' => 'vocab', 'title' => '顏色', 'language_code' => 'vi', 'license' => 'CC-BY-4.0']);
        $this->get('/sets/'.Set::latest('created_at')->firstOrFail()->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->where('uploadQuota', ['used_bytes' => 0, 'limit_bytes' => null]));
    }

    public function test_uploaded_audio_is_converted_to_mono_aac(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->teacher)->post('/media', [
            'kind' => 'audio',
            'file' => UploadedFile::fake()->createWithContent('word.wav', $this->sineWave()),
            'rights' => '1',
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = Media::findOrFail($response->json('id'));
        $this->assertSame('audio/mp4', $media->mime);
        $this->assertStringEndsWith('.m4a', $media->path);
        $this->assertEqualsWithDelta(500, $media->duration_ms, 100);
    }

    /**
     * 瀏覽器錄音（T-06）原樣上傳：Chrome 錄的是 webm（Opus），Safari 是分段的 mp4（AAC），
     * Firefox 是 ogg（Opus）。上傳時的檔名是 recording.*，類型由伺服器判斷。
     */
    public function test_browser_recordings_are_converted_to_mono_aac(): void
    {
        Storage::fake('public');

        $formats = [
            'webm' => ['-c:a', 'libopus', '-f', 'webm'],
            'm4a' => ['-c:a', 'aac', '-f', 'mp4', '-movflags', 'frag_keyframe+empty_moov+default_base_moof'],
            'ogg' => ['-c:a', 'libopus', '-f', 'ogg'],
        ];
        foreach ($formats as $extension => $codec) {
            $path = tempnam(sys_get_temp_dir(), 'kq-recording-');
            Process::run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1.5:sample_rate=48000',
                ...$codec, $path,
            ])->throw();

            $response = $this->actingAs($this->teacher)->post('/media', [
                'kind' => 'audio',
                'file' => new UploadedFile($path, "recording.{$extension}", null, null, true),
                'rights' => '1',
            ], ['Accept' => 'application/json'])->assertCreated();
            @unlink($path);

            $media = Media::findOrFail($response->json('id'));
            $this->assertSame('audio/mp4', $media->mime, $extension);
            $this->assertEqualsWithDelta(1500, $media->duration_ms, 150, $extension);
        }
    }

    /**
     * 媒體的署名（docs/SPEC.md 第 9 節）：上傳時作者是老師、授權由編輯頁帶入題組的授權；
     * 編輯頁和內容一起儲存修改後的署名，只能改自己上傳的。
     */
    public function test_media_credits_are_saved_with_the_set_and_only_for_own_uploads(): void
    {
        Storage::fake('public');

        // 複製來的題組：詞條引用同事上傳的音檔，署名只能看不能改（例如教材的插圖也是這樣）
        $other = User::factory()->create(['name' => '李老師']);
        $theirs = Media::create([
            'kind' => 'audio', 'path' => 'media/x.m4a', 'mime' => 'audio/mp4', 'bytes' => 1, 'uploaded_by' => $other->id,
            'authors' => [['name' => 'Kancil Quiz']], 'license' => 'CC-BY-4.0', 'source' => 'AI 生成',
        ]);
        $source = Set::factory()->for($other, 'owner')->create(['language_code' => 'vi']);
        app(SetWriter::class)->write($source, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [['item' => ['text' => 'quả chuối', 'translation_zh' => '香蕉', 'audio_ids' => [$theirs->id]]]],
        ], $other);
        $set = app(SetCopier::class)->copy($source, $this->teacher);
        $entryId = $set->entries()->firstOrFail()->id;

        $response = $this->actingAs($this->teacher)->post('/media', [
            'kind' => 'image',
            'file' => UploadedFile::fake()->image('banana.png', 40, 40),
            'rights' => '1',
            'license' => 'CC-BY-SA-4.0',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJson(['author' => $this->teacher->name, 'license' => 'CC-BY-SA-4.0', 'source' => null, 'editable' => true]);
        $image = Media::findOrFail($response->json('id'));

        $this->post('/media', [
            'kind' => 'image',
            'file' => UploadedFile::fake()->image('banana.png', 40, 40),
            'rights' => '1',
            'license' => 'WTFPL',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('license');

        $payload = $this->vocabPayload([['quả chuối', '香蕉']]);
        $payload['entries'][0]['id'] = $entryId;
        $payload['entries'][0]['item']['image_id'] = $image->id;
        $payload['entries'][0]['item']['audio_ids'] = [$theirs->id];
        $payload['media_credits'] = [['id' => $image->id, 'author' => '王老師、陳同學 ', 'source' => '自行拍攝', 'license' => 'CC0-1.0']];
        $this->put("/sets/{$set->id}", $payload)->assertSessionHasNoErrors();

        $image->refresh();
        $this->assertSame([['name' => '王老師'], ['name' => '陳同學']], $image->authors);
        $this->assertSame('自行拍攝', $image->source);
        $this->assertSame('CC0-1.0', $image->license);

        $this->get("/sets/{$set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.item.image.author', '王老師、陳同學')
            ->where('entries.0.item.image.source', '自行拍攝')
            ->where('entries.0.item.image.license', 'CC0-1.0')
            ->where('entries.0.item.image.editable', true)
            ->where('entries.0.item.audio.0.author', 'Kancil Quiz')
            ->where('entries.0.item.audio.0.editable', false));

        // 版本的內容（交換格式）是新的署名
        $content = $set->fresh()?->currentRevision?->content();
        $this->assertNotNull($content);
        $this->assertEquals([(object) ['name' => '王老師'], (object) ['name' => '陳同學']], $content->entries[0]->item->image->authors);
        $this->assertSame('CC0-1.0', $content->entries[0]->item->image->license);

        // 只有署名變了：修訂紀錄說明是署名
        $payload['media_credits'][0]['source'] = '自行拍攝，2026 年';
        $this->put("/sets/{$set->id}", $payload)->assertSessionHasNoErrors();
        $this->get("/sets/{$set->id}")->assertInertia(fn (Assert $page) => $page
            ->where('revisions.0.changes.0.label', '修改署名'));

        // 別人的媒體不能改署名；空白的作者退回上傳的老師，授權可以改回沿用題組
        $payload['media_credits'] = [['id' => $theirs->id, 'author' => '我', 'source' => null, 'license' => 'CC-BY-4.0']];
        $this->put("/sets/{$set->id}", $payload)->assertSessionHasErrors('media_credits');
        $this->assertSame([['name' => 'Kancil Quiz']], $theirs->fresh()?->authors);

        $payload['media_credits'] = [['id' => $image->id, 'author' => '  ', 'source' => '', 'license' => null]];
        $this->put("/sets/{$set->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame([['name' => $this->teacher->name]], $image->fresh()?->authors);
        $this->assertNull($image->fresh()?->license);
        $this->assertNull($image->fresh()?->source);
    }

    /**
     * 一個詞可以有多個音檔（docs/SPEC.md 3.1）：依編輯頁的順序存，移除其中一個不影響其他的。
     */
    public function test_an_item_keeps_all_its_audio_files_in_order(): void
    {
        $set = $this->createSet();
        $audio = fn (string $path) => Media::create([
            'kind' => 'audio', 'path' => $path, 'mime' => 'audio/mp4', 'bytes' => 1, 'uploaded_by' => $this->teacher->id,
        ]);
        [$first, $second] = [$audio('media/a.m4a'), $audio('media/b.m4a')];

        $payload = $this->vocabPayload([['quả chuối', '香蕉']]);
        $payload['entries'][0]['item']['audio_ids'] = [$second->id, $first->id];
        $this->put("/sets/{$set->id}", $payload)->assertSessionHasNoErrors();

        $content = $set->fresh()?->currentRevision?->content();
        $this->assertSame(["media/{$second->id}.m4a", "media/{$first->id}.m4a"], array_column($content->entries[0]->item->audio, 'src'));
        $this->get("/sets/{$set->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->has('entries.0.item.audio', 2)
            ->where('entries.0.item.audio.0.id', $second->id)
            ->where('entries.0.item.audio.1.id', $first->id));

        $payload['entries'][0]['id'] = $content->entries[0]->id;
        $payload['entries'][0]['item']['audio_ids'] = [$first->id];
        $this->put("/sets/{$set->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(["media/{$first->id}.m4a"], array_column($set->fresh()?->currentRevision?->content()->entries[0]->item->audio, 'src'));

        $payload['entries'][0]['item']['audio_ids'] = [$first->id, $second->id, $audio('media/c.m4a')->id, $audio('media/d.m4a')->id];
        $this->put("/sets/{$set->id}", $payload)->assertSessionHasErrors('entries.0.item.audio_ids');
    }

    public function test_uploads_require_the_rights_confirmation(): void
    {
        $this->actingAs($this->teacher)->post('/media', [
            'kind' => 'image',
            'file' => UploadedFile::fake()->image('banana.png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('rights');
    }

    public function test_a_teacher_creates_an_activity_and_switches_games(): void
    {
        $set = $this->createSet();
        $this->put("/sets/{$set->id}", $this->vocabPayload([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']]));

        $this->get("/sets/{$set->id}/activities/create")->assertInertia(fn (Assert $page) => $page
            ->component('activities/Create')
            ->has('games', 7)
            ->where('content.entries.0.item.text', 'quả chuối'));

        $this->post("/sets/{$set->id}/activities", ['game_id' => 'quiz', 'options' => ['autoAdvance' => false]])->assertRedirect();
        $quiz = Activity::where('game_id', 'quiz')->firstOrFail();
        $this->assertSame(['revealAnswer' => true, 'autoAdvance' => false], $quiz->options);

        $this->get("/activities/{$quiz->id}")->assertInertia(fn (Assert $page) => $page
            ->component('activities/Show')
            ->where('playUrl', route('play', $quiz))
            ->where('qrSvg', fn (string $svg) => str_contains($svg, '<svg')));

        // T-10：同一題組一鍵換成其他遊戲
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'maze-quiz'])->assertRedirect();
        $this->assertSame(2, $set->activities()->count());

        $this->get("/p/{$quiz->id}")->assertOk()->assertSee("data-activity=\"{$quiz->id}\"", false);
    }

    public function test_flash_cards_and_match_up_validate_their_options(): void
    {
        $set = $this->createSet();
        $this->put("/sets/{$set->id}", $this->vocabPayload([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']]));

        $this->post("/sets/{$set->id}/activities", ['game_id' => 'match-up', 'options' => ['pairsPerPage' => 9]])
            ->assertSessionHasErrors('options');
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'match-up', 'options' => ['pairsPerPage' => 4]])->assertRedirect();
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'flash-cards', 'options' => ['startWith' => 'back']])->assertRedirect();

        $this->assertSame(['pairsPerPage' => 4], Activity::where('game_id', 'match-up')->firstOrFail()->options);
        $this->assertSame(['startWith' => 'back', 'autoPlayAudio' => true], Activity::where('game_id', 'flash-cards')->firstOrFail()->options);

        // 遊戲名稱來自 manifest，不寫死在頁面中
        $games = fn (iterable $activities) => collect($activities)->pluck('game')->sort()->values()->all() === ['字卡', '配對'];
        $this->get("/sets/{$set->id}/edit")->assertInertia(fn (Assert $page) => $page->where('activities', $games));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('activities', $games));
    }

    public function test_card_wall_and_spin_wheel_validate_their_options(): void
    {
        $set = $this->createSet();
        $this->put("/sets/{$set->id}", $this->vocabPayload([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']]));

        $this->post("/sets/{$set->id}/activities", ['game_id' => 'spin-wheel', 'options' => ['sliceLabel' => 'emoji']])
            ->assertSessionHasErrors('options');
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'spin-wheel', 'options' => ['sliceLabel' => 'number']])->assertRedirect();
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'card-wall'])->assertRedirect();

        $this->assertSame(
            ['startWith' => 'front', 'sliceLabel' => 'number', 'removeAfterSpin' => true, 'autoPlayAudio' => true],
            Activity::where('game_id', 'spin-wheel')->firstOrFail()->options,
        );
        $this->assertSame(['startWith' => 'front', 'autoPlayAudio' => true], Activity::where('game_id', 'card-wall')->firstOrFail()->options);
    }

    public function test_a_teacher_previews_a_game_before_creating_the_activity(): void
    {
        $set = $this->createSet();
        $this->put("/sets/{$set->id}", $this->vocabPayload([['quả chuối', '香蕉'], ['quả táo', '蘋果'], ['quả cam', '柳橙']]));
        $url = fn (array $query) => "/sets/{$set->id}/activities/preview?".http_build_query($query);

        // T-08：選好遊戲與設定後先試玩，播放格式直接帶在頁面中
        $html = $this->get($url(['game' => 'quiz', 'options' => json_encode(['autoAdvance' => false])]))
            ->assertOk()
            ->assertSee('data-preview="1"', false)
            ->getContent();
        $this->assertIsString($html);
        $this->assertSame(1, preg_match('#<script type="application/json" id="kq-playback">(.+?)</script>#s', $html, $match));
        $playback = json_decode($match[1], flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], app(KancilFormat::class)->activityErrors($playback));
        $this->assertSame('quiz', $playback->game->id);
        $this->assertFalse($playback->game->options->autoAdvance);
        $this->assertTrue($playback->game->options->revealAnswer);
        $this->assertSame($set->fresh()?->current_revision_id, $playback->set_revision_id);

        // 預覽不建立活動
        $this->assertSame(0, $set->activities()->count());

        $this->get($url(['game' => 'nope']))->assertUnprocessable();
        $this->get($url(['game' => 'quiz', 'options' => '{"autoAdvance":"yes"}']))->assertUnprocessable();
        $this->get($url(['game' => 'quiz', 'options' => 'not json']))->assertUnprocessable();

        $this->actingAs(User::factory()->create())->get($url(['game' => 'quiz']))->assertForbidden();
    }

    public function test_activities_reject_invalid_options_and_too_few_entries(): void
    {
        $set = $this->createSet();

        $this->post("/sets/{$set->id}/activities", ['game_id' => 'quiz'])->assertSessionHasErrors('game_id');

        $this->put("/sets/{$set->id}", $this->vocabPayload([['quả chuối', '香蕉'], ['quả táo', '蘋果']]));
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'quiz', 'options' => ['autoAdvance' => 'yes']])
            ->assertSessionHasErrors('options');
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'nope'])->assertSessionHasErrors('game_id');
    }

    /**
     * 0.5 秒、440 Hz 的 16-bit 單聲道 WAV。
     */
    private function sineWave(): string
    {
        $rate = 8000;
        $samples = '';
        for ($i = 0; $i < $rate / 2; $i++) {
            $samples .= pack('v', (int) (sin(2 * M_PI * 440 * $i / $rate) * 8000) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }
}
