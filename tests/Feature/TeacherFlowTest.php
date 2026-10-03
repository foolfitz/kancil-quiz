<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Media;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->has('games', 2)
            ->where('content.entries.0.item.text', 'quả chuối'));

        $this->post("/sets/{$set->id}/activities", ['game_id' => 'quiz', 'options' => ['autoAdvance' => false]])->assertRedirect();
        $quiz = Activity::where('game_id', 'quiz')->firstOrFail();
        $this->assertSame(['revealAnswer' => true, 'autoAdvance' => false], $quiz->options);

        $this->get("/activities/{$quiz->id}")->assertInertia(fn (Assert $page) => $page
            ->component('activities/Show')
            ->where('playUrl', route('play', $quiz))
            ->where('qrSvg', fn (string $svg) => str_contains($svg, '<svg')));

        // T-10：同一題組一鍵換成其他遊戲
        $this->post("/sets/{$set->id}/activities", ['game_id' => 'maze-chase'])->assertRedirect();
        $this->assertSame(2, $set->activities()->count());

        $this->get("/p/{$quiz->id}")->assertOk()->assertSee("data-activity=\"{$quiz->id}\"", false);
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
