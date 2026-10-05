<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 播放頁的檢舉（docs/SPEC.md S-07、A-05）：只有活動與原因，不存 IP 或檢舉人的資料。
 *
 * @property int $id
 * @property string $activity_id
 * @property string $reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by
 */
#[Fillable(['activity_id', 'reason', 'created_at', 'resolved_at', 'resolved_by'])]
class Report extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * 活動被老師刪除（soft delete）後仍然看得到；30 天後真正刪除時，檢舉也一起刪除。
     *
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
