<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 教材的冊與課（docs/SPEC.md 3.6）。只存課名；匯入詞彙的課另有一個教材題組（set_id），有課文句子的課再有一個句子題組（sentence_set_id）。
 *
 * @property int $id
 * @property string $language_code
 * @property int $volume
 * @property int $lesson
 * @property string|null $title_zh
 * @property string|null $title_native
 * @property string|null $set_id
 * @property int|null $imported_revision 教材題組最近一次匯入詞彙後的版本號（App\Curriculum\CurriculumImporter::editedOnSite()）
 * @property string|null $sentence_set_id 這一課的句子題組（課文句子）
 * @property int|null $sentence_imported_revision 句子題組最近一次匯入後的版本號
 */
#[Fillable(['language_code', 'volume', 'lesson', 'title_zh', 'title_native', 'set_id', 'imported_revision', 'sentence_set_id', 'sentence_imported_revision'])]
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

    /**
     * 這一課的教材題組：由該課的詞彙匯入（App\Curriculum\CurriculumImporter）。
     *
     * @return BelongsTo<Set, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    /**
     * 這一課的句子題組：由該課的課文句子匯入（docs/SPEC.md 3.6）。
     *
     * @return BelongsTo<Set, $this>
     */
    public function sentenceSet(): BelongsTo
    {
        return $this->belongsTo(Set::class, 'sentence_set_id');
    }
}
