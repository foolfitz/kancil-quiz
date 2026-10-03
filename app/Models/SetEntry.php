<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 題組中的一題。詞彙組引用詞條（item_id）；問答組的題目存在 payload，
 * 形狀與交換格式的 question 相同，只是媒體以 media 的 ID（audio_id、image_id）表示。
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

    /**
     * @return BelongsTo<Set, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }
}
