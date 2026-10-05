<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 教材試玩的人氣統計：一課、一個遊戲、一天（台灣時間）一列，只有次數（docs/SPEC.md S-06、A-04）。
 * 由 App\Curriculum\PlayCounts 累計與彙總。
 *
 * @property int $id
 * @property int $curriculum_ref_id
 * @property string $game_id
 * @property CarbonImmutable $date
 * @property int $starts
 * @property int $finishes
 */
#[Fillable(['curriculum_ref_id', 'game_id', 'date', 'starts', 'finishes'])]
class CurriculumPlay extends Model
{
    public $timestamps = false;

    // 只有 date 一個日期欄位，存成 Y-m-d 才能和 PlayCounts::record() 寫入的列對上唯一鍵
    protected $dateFormat = 'Y-m-d';

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<CurriculumRef, $this>
     */
    public function curriculumRef(): BelongsTo
    {
        return $this->belongsTo(CurriculumRef::class);
    }
}
