<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 題組中的一題。詞彙組引用詞條（item_id）；問答組的題目存在 payload，
 * 形狀與交換格式的 question 相同，只是媒體以 media 的 ID（audio_id、image_id）表示。
 *
 * payload 引用的媒體另外記在對照表 set_entry_media（media()），和詞條的 item_media 一樣，
 * 創作者頁面的統計與 kancil:prune 的媒體清除才不必把平台上每一題的 payload 讀出來。
 * 經過 model 儲存時自動同步；繞過 model 事件寫入 payload（例如 insert()）的程式要自己呼叫 syncMedia()。
 *
 * @property string $id
 * @property string $set_id
 * @property int $position
 * @property string|null $item_id
 * @property array<string, mixed>|null $payload 問答組的題目：{stem: {text?, audio_id?, image_id?}, options: [{id, text?, image_id?, correct}]}
 */
#[Fillable(['set_id', 'position', 'item_id', 'payload'])]
class SetEntry extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (SetEntry $entry) {
            // 詞彙組的詞條沒有 payload，建立時不必動對照表
            if ($entry->wasChanged('payload') || ($entry->wasRecentlyCreated && $entry->payload !== null)) {
                $entry->syncMedia();
            }
        });
    }

    /**
     * @return BelongsTo<Set, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    /**
     * 問答題引用的媒體，由 payload 同步（set_entry_media）；詞彙組的媒體在詞條上（Item::media()）。
     *
     * @return BelongsToMany<Media, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'set_entry_media');
    }

    /**
     * 把 payload 引用的媒體寫進對照表。同一個媒體在一題中出現兩次只記一列；刪除題目時隨外鍵 cascade 刪除。
     */
    public function syncMedia(): void
    {
        $this->media()->sync(array_values(array_unique($this->mediaIds())));
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * 問答題引用的媒體 ID（題幹的音檔與圖片、選項的圖片）。詞彙組的媒體在詞條上。
     *
     * @return list<string>
     */
    public function mediaIds(): array
    {
        $question = $this->payload ?? [];

        return array_values(array_filter([
            $question['stem']['audio_id'] ?? null,
            $question['stem']['image_id'] ?? null,
            ...array_map(fn ($option) => $option['image_id'] ?? null, $question['options'] ?? []),
        ], fn ($id) => is_string($id) && $id !== ''));
    }
}
