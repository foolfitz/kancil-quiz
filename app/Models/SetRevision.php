<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * 不可變的題組版本（docs/SPEC.md 3.3）。content 是交換格式的 set.json，媒體為相對路徑。
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

    public function content(): stdClass
    {
        $content = json_decode($this->getAttribute('content'), flags: JSON_THROW_ON_ERROR);
        assert($content instanceof stdClass);

        return $content;
    }
}
