<?php

namespace App\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * 上傳的音檔與圖片統一轉檔後存成不可變的 Media（docs/SPEC.md 第 9 節）。
 *
 * spec 寫的是以佇列任務轉檔；M1 先在上傳的請求中同步轉檔，老師上傳後立即能看到結果。
 * 使用量上升後再改成佇列。
 */
class MediaProcessor
{
    public const IMAGE_MAX_EDGE = 1024;

    public const THUMBNAIL_EDGE = 256;

    /**
     * 統一轉成單聲道 AAC（.m4a），並做音量標準化（EBU R128 loudnorm），讓 iPad 能播放、各老師錄的音量一致。
     * 瀏覽器錄音（T-06）也走這裡：Chrome 錄的是 webm（Opus），Safari 是 mp4（AAC），由 ffmpeg 判斷格式。
     *
     * @param  array{authors?: list<array{name: string, url?: string}>|null, license?: string|null, source?: string|null}  $attribution
     */
    public function audio(UploadedFile $file, User $user, array $attribution = []): Media
    {
        // ffmpeg 依副檔名決定輸出格式，所以另外加上 .m4a；tempnam 建立的原檔也要刪
        $base = tempnam(sys_get_temp_dir(), 'kq-audio-');
        $output = $base.'.m4a';

        try {
            $result = Process::timeout(60)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $file->getRealPath(),
                '-vn', '-ac', '1', '-ar', '44100',
                '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11',
                '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart',
                $output,
            ]);

            if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
                Log::warning('音檔轉檔失敗', [
                    'mime' => $file->getMimeType(),
                    'bytes' => $file->getSize(),
                    'error' => Str::limit(trim($result->errorOutput()), 2000),
                ]);
                throw ValidationException::withMessages(['file' => '無法讀取這個音檔，請換一個檔案試試。']);
            }

            $duration = Process::run([
                'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $output,
            ])->output();

            return $this->store($output, 'm4a', $user, $attribution, [
                'kind' => 'audio',
                'mime' => 'audio/mp4',
                'duration_ms' => is_numeric(trim($duration)) ? (int) round((float) trim($duration) * 1000) : null,
            ]);
        } finally {
            @unlink($output);
            @unlink($base);
        }
    }

    /**
     * 轉成長邊最多 1024 px 的 WebP，另產生縮圖。
     *
     * @param  array{authors?: list<array{name: string, url?: string}>|null, license?: string|null, source?: string|null}  $attribution
     */
    public function image(UploadedFile $file, User $user, array $attribution = []): Media
    {
        return $this->imageFromPath((string) $file->getRealPath(), $user, $attribution);
    }

    /**
     * 同 image()，來源是本機檔案，例如匯入教材的插圖（App\Curriculum\CurriculumImporter）。
     *
     * @param  array{authors?: list<array{name: string, url?: string}>|null, license?: string|null, source?: string|null}  $attribution
     */
    public function imageFromPath(string $path, User $user, array $attribution = []): Media
    {
        $manager = ImageManager::usingDriver(Driver::class);

        try {
            $image = $manager->decode($path)->orient()->scaleDown(self::IMAGE_MAX_EDGE, self::IMAGE_MAX_EDGE);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => '無法讀取這張圖片，請換一個檔案試試。']);
        }

        $main = tempnam(sys_get_temp_dir(), 'kq-image-');
        $thumb = tempnam(sys_get_temp_dir(), 'kq-thumb-');

        try {
            file_put_contents($main, $image->encode(new WebpEncoder(quality: 82))->toString());
            $width = $image->width();
            $height = $image->height();
            file_put_contents($thumb, $image->scaleDown(self::THUMBNAIL_EDGE, self::THUMBNAIL_EDGE)->encode(new WebpEncoder(quality: 75))->toString());

            $thumbnailPath = 'media/'.Str::random(32).'.webp';
            Storage::disk('public')->put($thumbnailPath, (string) file_get_contents($thumb));

            return $this->store($main, 'webp', $user, $attribution, [
                'kind' => 'image',
                'mime' => 'image/webp',
                'width' => $width,
                'height' => $height,
                'thumbnail_path' => $thumbnailPath,
            ]);
        } finally {
            @unlink($main);
            @unlink($thumb);
        }
    }

    /**
     * 檔名是隨機字串，不可猜測（docs/SPEC.md 第 9 節）。
     *
     * @param  array{authors?: list<array{name: string, url?: string}>|null, license?: string|null, source?: string|null}  $attribution
     * @param  array<string, mixed>  $attributes
     */
    private function store(string $localPath, string $extension, User $user, array $attribution, array $attributes): Media
    {
        $path = 'media/'.Str::random(32).'.'.$extension;
        Storage::disk('public')->put($path, (string) file_get_contents($localPath));

        return Media::create([
            ...$attributes,
            'path' => $path,
            'bytes' => (int) filesize($localPath),
            'authors' => $attribution['authors'] ?? null,
            'license' => $attribution['license'] ?? null,
            'source' => $attribution['source'] ?? null,
            'uploaded_by' => $user->id,
        ]);
    }
}
