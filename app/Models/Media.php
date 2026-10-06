<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * 轉檔完成的音檔或圖片。檔案內容不可變，替換時產生新的 Media；署名資料可以修正
 * （docs/SPEC.md 第 9 節）。
 *
 * @property string $id
 * @property string $kind audio、image
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property int|null $duration_ms
 * @property int|null $width
 * @property int|null $height
 * @property string|null $thumbnail_path
 * @property list<array{name: string, url?: string}>|null $authors
 * @property string|null $license
 * @property string|null $source
 * @property int $uploaded_by
 */
#[Fillable(['kind', 'path', 'mime', 'bytes', 'duration_ms', 'width', 'height', 'thumbnail_path', 'authors', 'license', 'source', 'uploaded_by'])]
class Media extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'authors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * 引用這個媒體的詞條（詞彙組）；問答組的媒體以 ID 寫在 SetEntry 的 payload 中。
     *
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_media');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path === null ? null : Storage::disk('public')->url($this->thumbnail_path);
    }

    /**
     * 在交換格式（zip）中的相對路徑，例：media/<ID>.m4a。
     */
    public function zipPath(): string
    {
        return 'media/'.$this->id.'.'.pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * 交換格式中的媒體物件；署名資料只在有填寫時輸出，省略時沿用外層。
     *
     * @return array<string, mixed>
     */
    public function toExchange(): array
    {
        return array_filter([
            'src' => $this->zipPath(),
            'authors' => $this->authors ?: null,
            'license' => $this->license,
            'source' => $this->source,
        ], fn ($value) => $value !== null);
    }
}
