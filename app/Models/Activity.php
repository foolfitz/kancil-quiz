<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 活動：題組＋遊戲＋遊戲設定（docs/SPEC.md 3.4）。指向題組而不是特定版本，播放時一律使用最新版本。
 *
 * @property string $id
 * @property string $set_id
 * @property string $game_id
 * @property string $game_version
 * @property array<string, mixed> $options
 * @property string $mode
 * @property CarbonImmutable|null $opens_at
 * @property CarbonImmutable|null $closes_at
 * @property int $owner_id
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['set_id', 'game_id', 'game_version', 'options', 'mode', 'opens_at', 'closes_at', 'owner_id'])]
class Activity extends Model
{
    use HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Attempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    public function isOpen(): bool
    {
        return ($this->opens_at === null || $this->opens_at->isPast())
            && ($this->closes_at === null || $this->closes_at->isFuture());
    }
}
