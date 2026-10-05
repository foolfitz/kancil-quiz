<?php

namespace Tests\Feature;

use App\Corpus\SetWriter;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 只有播放頁可以被其他網站放進 iframe（DenyFraming）。教材試玩的部分在 CurriculumTest。
 */
class FramingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_other_than_the_player_cannot_be_framed(): void
    {
        $this->get('/')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        // Filament 後台不經過 web 群組
        $this->get('/admin')->assertRedirect('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $teacher = User::factory()->create();
        $this->actingAs($teacher)->get('/settings/profile')->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_activities_can_be_embedded(): void
    {
        $teacher = User::factory()->create();
        $set = Set::factory()->for($teacher, 'owner')->create(['language_code' => 'id']);
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [['item' => ['text' => 'ayah', 'translation_zh' => '爸爸']], ['item' => ['text' => 'ibu', 'translation_zh' => '媽媽']]],
        ], $teacher);
        $activity = $set->activities()->create(['game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $teacher->id]);

        $this->get("/p/{$activity->id}")->assertOk()->assertHeaderMissing('X-Frame-Options');
    }
}
