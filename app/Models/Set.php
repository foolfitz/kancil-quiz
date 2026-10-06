<?php

namespace App\Models;

use App\Models\Concerns\HasUlids;
use Carbon\CarbonImmutable;
use Database\Factories\SetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property list<string>|null $tags
 * @property list<array{name: string, url?: string}>|null $authors 複製來源的作者，不含目前的擁有者
 * @property string|null $share_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'title', 'description', 'language_code', 'owner_id', 'visibility', 'review_status', 'faces', 'forked_from_id', 'license', 'current_revision_id', 'tags', 'authors', 'share_token'])]
class Set extends Model
{
    /** @use HasFactory<SetFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public const KINDS = ['vocab', 'quiz'];

    /**
     * 老師可以為自己的題組選擇的授權，第一個是預設（docs/SPEC.md D-3）。
     * 教材題組照原教材標示 CC-BY-NC-ND-4.0（App\Curriculum\Textbook），不在這裡面。
     */
    public const LICENSES = ['CC-BY-4.0', 'CC-BY-SA-4.0', 'CC0-1.0'];

    public const VISIBILITIES = ['private', 'unlisted', 'public'];

    public const REVIEW_STATUSES = ['none', 'pending', 'approved', 'rejected'];

    protected function casts(): array
    {
        return [
            'faces' => 'array',
            'tags' => 'array',
            'authors' => 'array',
        ];
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /**
     * 公開而且擁有者沒有被停用：列在共備庫、任何老師都能檢視、複製與匯出（docs/SPEC.md 3.2、A-05）。
     * 擁有者被停用時，審核者與管理員仍可以看到並下架它（isPublic()）。
     */
    public function isListed(): bool
    {
        return $this->isPublic() && ! $this->owner->isDisabled();
    }

    /**
     * 同事的分享連結（docs/SPEC.md T-17）：擁有者被停用時一樣失效。
     */
    public static function sharedBy(string $token): self
    {
        return self::where('share_token', $token)
            ->where('visibility', 'unlisted')
            ->whereHas('owner', fn (Builder $owner) => $owner->whereNull('disabled_at'))
            ->firstOrFail();
    }

    /**
     * @param  Builder<Set>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('visibility', 'public')->whereHas('owner', fn (Builder $owner) => $owner->whereNull('disabled_at'));
    }

    /**
     * 教材題組：由某一課的詞彙匯入，所有老師都能直接用來建立活動（docs/SPEC.md 3.6）。
     */
    public function isTextbook(): bool
    {
        return $this->textbookLesson !== null;
    }

    /**
     * 交換格式的 authors：複製來源的作者在前，目前的擁有者加在最後（docs/SPEC.md 第 5 節）。
     * 擁有者以創作者資料的署名名稱與網址列出（User::author()，T-20），改了署名下一次匯出就跟著變。
     * 教材題組只列資料檔中的作者，不列擁有它的教材帳號（3.6）。
     *
     * @return list<array{name: string, url?: string}>
     */
    public function effectiveAuthors(): array
    {
        $authors = $this->authors ?? [];
        if ($authors !== [] && $this->isTextbook()) {
            return $authors;
        }
        // 刪除帳號的老師：刪除時已經把名字寫進 authors（App\Auth\AccountDeletion），不再加上「已刪除的使用者」
        if (! $this->owner->isAnonymized() && ! in_array($this->owner->attributionName(), array_column($authors, 'name'), true)) {
            $authors[] = $this->owner->author();
        }

        return $authors;
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

    /**
     * 對應的教材冊課（docs/SPEC.md 3.6）。
     *
     * @return BelongsToMany<CurriculumRef, $this>
     */
    public function curriculumRefs(): BelongsToMany
    {
        return $this->belongsToMany(CurriculumRef::class, 'set_curriculum_ref')
            ->orderBy('volume')
            ->orderBy('lesson');
    }

    /**
     * 教材題組對應的那一課；一般題組為 null。
     *
     * @return HasOne<CurriculumRef, $this>
     */
    public function textbookLesson(): HasOne
    {
        return $this->hasOne(CurriculumRef::class);
    }

    /**
     * @return HasMany<SetReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(SetReview::class)->orderByDesc('id');
    }

    /**
     * 複製來源。來源刪除後仍保留紀錄，以顯示出處。
     *
     * @return BelongsTo<Set, $this>
     */
    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(Set::class, 'forked_from_id')->withTrashed();
    }
}
