<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 學生玩一次活動（docs/SPEC.md 3.5）。correct_count 由伺服器判定，game_score 只供顯示（7.4）。
 *
 * @property string $id
 * @property string $activity_id
 * @property string $set_revision_id
 * @property int $seed
 * @property string|null $player_label
 * @property string $token_hash
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $correct_count
 * @property int $round_count
 * @property int|null $game_score
 * @property int|null $duration_ms
 */
#[Fillable(['activity_id', 'set_revision_id', 'seed', 'player_label', 'token_hash', 'started_at', 'completed_at', 'correct_count', 'round_count', 'game_score', 'duration_ms'])]
#[Hidden(['token_hash'])]
class Attempt extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return BelongsTo<SetRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SetRevision::class, 'set_revision_id');
    }

    /**
     * @return HasMany<AttemptResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(AttemptResponse::class)->orderBy('id');
    }

    public function hasToken(string $token): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $token));
    }
}
