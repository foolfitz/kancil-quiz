<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Carbon\CarbonImmutable;
use Database\Factories\SetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 題組（docs/SPEC.md 3.2）。內容每次變動都會產生不可變的版本（3.3），活動使用最新版本。
 *
 * @property string $id
 * @property string $kind
 * @property string $title
 * @property string|null $description
 * @property string $language_code
 * @property int $owner_id
 * @property string $visibility
 * @property string $review_status
 * @property array{prompt: list<string>, answer: list<string>}|null $faces
 * @property string|null $forked_from_id
 * @property string $license
 * @property string|null $current_revision_id
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'title', 'description', 'language_code', 'owner_id', 'visibility', 'review_status', 'faces', 'forked_from_id', 'license', 'current_revision_id'])]
class Set extends Model
{
    /** @use HasFactory<SetFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public const KINDS = ['vocab', 'quiz'];

    public const VISIBILITIES = ['private', 'unlisted', 'public'];

    protected function casts(): array
    {
        return [
            'faces' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_code');
    }

    /**
     * @return HasMany<SetEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SetEntry::class)->orderBy('position');
    }

    /**
     * @return HasMany<SetRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(SetRevision::class);
    }

    /**
     * @return BelongsTo<SetRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(SetRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
