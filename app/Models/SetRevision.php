<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * 不可變的題組版本（docs/SPEC.md 3.3）。content 是交換格式的 set.json，媒體為相對路徑。
 * created_by 記錄是誰修改的，也是審核者修正公開語料時的修訂紀錄（C-03）。
 *
 * @property string $id
 * @property string $set_id
 * @property int $number
 * @property string $content_hash
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 *
 * content 以原始 JSON 字串保存，用 content() 解成物件，避免陣列與物件（[] 與 {}）混淆。
 */
#[Fillable(['set_id', 'number', 'content', 'content_hash', 'created_by'])]
class SetRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Set, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function content(): stdClass
    {
        $content = json_decode($this->getAttribute('content'), flags: JSON_THROW_ON_ERROR);
        assert($content instanceof stdClass);

        return $content;
    }
}
