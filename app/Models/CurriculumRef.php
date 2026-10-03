<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 教材的冊與課對照，只存對照資訊，不存教材內容（docs/SPEC.md 3.6）。
 */
#[Fillable(['language_code', 'volume', 'lesson', 'title_zh'])]
class CurriculumRef extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_code');
    }
}
