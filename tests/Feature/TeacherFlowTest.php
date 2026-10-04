<?php

namespace Tests\Feature;

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
            ->has('games', 4)
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
