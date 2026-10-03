<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 詞條：一個詞或短句。屬於建立它的老師，題組只引用擁有者自己的詞條（docs/SPEC.md 3.1）。
 *
 * @property string $id
 * @property string $language_code
 * @property string $text
 * @property string|null $romanization
 * @property string $translation_zh
 * @property list<string>|null $tags
 * @property int $owner_id
 * @property list<array{name: string}>|null $authors
 * @property string|null $license
 * @property string|null $source
 * @property string|null $forked_from_id
 */
#[Fillable(['language_code', 'text', 'romanization', 'translation_zh', 'tags', 'owner_id', 'authors', 'license', 'source', 'forked_from_id'])]
class Item extends Model
{
    use HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'authors' => 'array',
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
     * @return BelongsToMany<Media, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'item_media')
            ->withPivot(['role', 'position'])
            ->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<Media, $this>
     */
    public function audio(): BelongsToMany
    {
        return $this->media()->wherePivot('role', 'audio');
    }

    /**
     * @return BelongsToMany<Media, $this>
     */
    public function images(): BelongsToMany
    {
        return $this->media()->wherePivot('role', 'image');
    }

    /**
     * @return HasMany<SetEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SetEntry::class);
    }
}
