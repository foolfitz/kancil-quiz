<?php

namespace Tests\Feature;

use App\Corpus\SetExport;
use App\Corpus\SetWriter;
use App\Models\Media;
use App\Models\Set;
use App\Models\User;
use App\Support\KancilFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * 匯出題組的 zip（docs/SPEC.md T-15、6.2），權限依題組的可見性（3.2）。
 */
class SetExportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $colleague;

    private Set $set;

    private Media $image;

    private Media $audio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('media/ab/banana.webp', 'webp-bytes');
        Storage::disk('public')->put('media/cd/chuoi.m4a', 'm4a-bytes');

        $this->owner = User::factory()->create(['name' => '王老師']);
        $this->colleague = User::factory()->create(['name' => '李老師']);
        $this->image = Media::create([
            'kind' => 'image', 'path' => 'media/ab/banana.webp', 'mime' => 'image/webp', 'bytes' => 10,
            'uploaded_by' => $this->owner->id, 'authors' => [['name' => '陳同學']], 'source' => '自行拍攝',
        ]);
        $this->audio = Media::create([
            'kind' => 'audio', 'path' => 'media/cd/chuoi.m4a', 'mime' => 'audio/mp4', 'bytes' => 9,
            'uploaded_by' => $this->owner->id,
        ]);

        $this->set = Set::factory()->for($this->owner, 'owner')->create(['title' => '水果（越南語）']);
        app(SetWriter::class)->write($this->set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [
                ['item' => ['text' => 'quả chuối', 'translation_zh' => '香蕉', 'audio_ids' => [$this->audio->id], 'image_id' => $this->image->id]],
                ['item' => ['text' => 'quả táo', 'translation_zh' => '蘋果']],
            ],
        ], $this->owner);
        $this->set->refresh();
    }

    /**
     * 下載的 zip 中每個檔案的內容，以檔名為鍵。
     *
     * @param  TestResponse<BinaryFileResponse>  $response
     * @return array<string, string>
     */
    private function unzip(TestResponse $response): array
    {
        $response->assertOk()->assertDownload("set-{$this->set->id}.zip");
        $baseResponse = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $baseResponse);
        $path = $baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path); // 測試中回應不會真的送出，暫存檔要自己刪

        ksort($files);

        return $files;
    }

    private function publish(): void
    {
        $this->set->update(['visibility' => 'public', 'review_status' => 'approved']);
    }

    public function test_the_zip_holds_the_current_revision_its_media_and_the_credits(): void
    {
        $files = $this->unzip($this->actingAs($this->owner)->get("/sets/{$this->set->id}/export"));

        $imagePath = "media/{$this->image->id}.webp";
        $audioPath = "media/{$this->audio->id}.m4a";
        $expected = ['LICENSE.txt', $audioPath, $imagePath, 'set.json'];
        sort($expected);
        $this->assertSame($expected, array_keys($files));

        // set.json 就是最新版本的內容，媒體是 zip 內的相對路徑（6.2、6.5）
        $set = json_decode($files['set.json']);
        $this->assertInstanceOf(stdClass::class, $set);
        $this->assertEquals($this->set->currentRevision?->content(), $set);
        $this->assertSame([], app(KancilFormat::class)->setErrors($set));
        $this->assertSame($imagePath, $set->entries[0]->item->image->src);
        $this->assertSame('webp-bytes', $files[$imagePath]);
        $this->assertSame('m4a-bytes', $files[$audioPath]);

        // 圖片有自己的作者與出處；音檔沿用詞條與題組，不另外列出
        $license = $files['LICENSE.txt'];
        $this->assertStringContainsString('水果（越南語）', $license);
        $this->assertStringContainsString('授權：CC BY 4.0（https://creativecommons.org/licenses/by/4.0/）', $license);
        $this->assertStringContainsString('作者：王老師', $license);
        $this->assertStringContainsString("- 第 1 題 quả chuối（香蕉）的圖片（{$imagePath}）：作者 陳同學；出處 自行拍攝\n", $license);
        $this->assertStringNotContainsString('的發音', $license);
        $this->assertStringNotContainsString('quả táo', $license);
    }

    public function test_private_sets_can_only_be_exported_by_their_owner(): void
    {
        $this->get("/sets/{$this->set->id}/export")->assertRedirect('/login');
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}/export")->assertForbidden();
        // 開放資料的網址不透露私人題組是否存在
        $this->get("/api/v1/sets/{$this->set->id}/export")->assertNotFound();
    }

    public function test_colleagues_export_unlisted_sets_through_the_share_link(): void
    {
        $this->actingAs($this->owner)->post("/sets/{$this->set->id}/share");
        $token = (string) $this->set->fresh()?->share_token;

        $this->unzip($this->actingAs($this->colleague)->get("/shared/{$token}/export"));
        $this->actingAs($this->colleague)->get("/sets/{$this->set->id}/export")->assertForbidden();
        $this->get("/api/v1/sets/{$this->set->id}/export")->assertNotFound();

        $this->actingAs($this->owner)->delete("/sets/{$this->set->id}/share");
        $this->actingAs($this->colleague)->get("/shared/{$token}/export")->assertNotFound();
    }

    public function test_anyone_can_download_public_sets_without_logging_in(): void
    {
        $this->publish();

        $this->unzip($this->actingAs($this->colleague)->get("/sets/{$this->set->id}/export"));
        auth()->logout();
        $files = $this->unzip($this->get("/api/v1/sets/{$this->set->id}/export"));
        $this->assertArrayHasKey('set.json', $files);
    }

    public function test_pages_offer_the_download_only_to_those_who_may_export(): void
    {
        $this->actingAs($this->owner)->get("/sets/{$this->set->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('can.export', true));

        $this->actingAs($this->owner)->post("/sets/{$this->set->id}/share");
        $token = (string) $this->set->fresh()?->share_token;
        $this->actingAs($this->colleague)->get("/shared/{$token}")
            ->assertInertia(fn (Assert $page) => $page->where('can.export', true)->where('token', $token));

        $empty = Set::factory()->for($this->owner, 'owner')->create();
        $this->actingAs($this->owner)->get("/sets/{$empty->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('can.export', false));
    }

    public function test_a_set_without_content_cannot_be_exported(): void
    {
        $empty = Set::factory()->for($this->owner, 'owner')->create();

        $this->actingAs($this->owner)->get("/sets/{$empty->id}/export")->assertNotFound();
    }

    public function test_the_credits_follow_the_inheritance_rules(): void
    {
        // 省略的欄位沿用外層：媒體沿用詞條，詞條沿用題組（6.5）
        $set = json_decode((string) json_encode([
            'id' => '01M3ZYQZ9RJK6VGH2N6XVR9QTH',
            'kind' => 'vocab',
            'title' => '第 1 冊第 3 課：Keluarga Saya 我的家人',
            'license' => 'CC-BY-4.0',
            'authors' => [['name' => 'Kancil Quiz']],
            'entries' => [[
                'id' => '01M3ZYR090HSK1QGN8BZGK07M3',
                'item' => [
                    'text' => 'ayah',
                    'translation_zh' => '爸爸',
                    'source' => '新住民語文學習教材 印尼語第1冊 第 3 課，第 26 頁',
                    'audio' => [['src' => 'media/01M3ZYR188M6EWJQJWHW2KP6YE.m4a']],
                    'image' => ['src' => 'media/01M3ZYR27GF5QQW62SVZ5NA266.webp', 'source' => 'AI 生成'],
                ],
            ]],
        ]));
        $this->assertInstanceOf(stdClass::class, $set);

        $license = SetExport::license($set, 3);

        $this->assertStringContainsString('Kancil Quiz 題組 01M3ZYQZ9RJK6VGH2N6XVR9QTH，第 3 版', $license);
        // 只列出與外層不同的欄位
        $this->assertStringContainsString("- 第 1 題 ayah（爸爸）：出處 新住民語文學習教材 印尼語第1冊 第 3 課，第 26 頁\n", $license);
        $this->assertStringContainsString("- 第 1 題 ayah（爸爸）的圖片（media/01M3ZYR27GF5QQW62SVZ5NA266.webp）：出處 AI 生成\n", $license);
        // 音檔沿用詞條的出處，與詞條相同就不另外列出
        $this->assertStringNotContainsString('的發音', $license);
    }
}
