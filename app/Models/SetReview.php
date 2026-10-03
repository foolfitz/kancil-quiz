<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 公開申請與審核的紀錄（docs/SPEC.md T-12、C-01）。只新增，不修改。
 *
 * @property int $id
 * @property string $set_id
 * @property string|null $set_revision_id
 * @property int $user_id
 * @property string $action
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
#[Fillable(['set_id', 'set_revision_id', 'user_id', 'action', 'note'])]
class SetReview extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['requested', 'withdrawn', 'approved', 'rejected', 'unpublished'];

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

    /**
     * @return BelongsTo<SetRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SetRevision::class, 'set_revision_id');
    }
}
