<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 創作者頁面的貢獻日曆（docs/SPEC.md T-20）：一位老師、一個題組、台灣時間的一天一列，
 * 只記那一天為這個題組產生了幾個版本。題組版本會被清除（第 5 節），所以另外累計（App\Profile\Contributions）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $set_id
 * @property string $date
 * @property int $revisions
 */
#[Fillable(['user_id', 'set_id', 'date', 'revisions'])]
class Contribution extends Model
{
    public $timestamps = false;

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
