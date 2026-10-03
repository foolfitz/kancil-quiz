<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一筆作答。correct 由伺服器判定，client_correct 是遊戲回報的判定（docs/SPEC.md 7.4）。
 *
 * @property int $id
 * @property string $attempt_id
 * @property string $entry_id
 * @property list<string> $presented
 * @property list<string>|null $selected
 * @property bool|null $correct
 * @property bool|null $client_correct
 * @property int|null $duration_ms
 */
#[Fillable(['attempt_id', 'entry_id', 'presented', 'selected', 'correct', 'client_correct', 'duration_ms'])]
class AttemptResponse extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'presented' => 'array',
            'selected' => 'array',
            'correct' => 'boolean',
            'client_correct' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Attempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }
}
