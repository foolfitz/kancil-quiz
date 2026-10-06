<?php

namespace App\Corpus;

use App\Models\CurriculumRef;
use App\Models\Set;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 共備庫與創作者頁面上的題組卡片（docs/SPEC.md T-13、T-20）：兩邊用同一個形狀，
 * 前端是 resources/js/components/kancil/SetCard.vue。擁有者以署名名稱顯示，有創作者頁面時附上連結。
 */
class SetCards
{
    /**
     * 列出 Set::listed() 的題組，最近更新的在前。
     *
     * @param  Builder<Set>  $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(Builder $query, int $perPage = 24): LengthAwarePaginator
    {
        return $query
            ->with(['owner', 'language', 'curriculumRefs', 'textbookLesson'])
            ->withCount('entries')
            ->latest('updated_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Set $set) => self::card($set));
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(Set $set): array
    {
        return [
            'id' => $set->id,
            'kind' => $set->kind,
            'title' => $set->title,
            'description' => $set->description,
            'language' => $set->language->name_zh,
            'owner' => $set->owner->attributionName(),
            'owner_url' => $set->owner->profileUrl(),
            'entries_count' => $set->entries_count,
            'curriculum' => $set->curriculumRefs->map(fn (CurriculumRef $ref) => ['volume' => $ref->volume, 'lesson' => $ref->lesson])->all(),
            'tags' => $set->tags ?? [],
            'forked' => $set->forked_from_id !== null,
            'textbook' => $set->isTextbook(),
            'updated_at' => $set->updated_at?->toIso8601String(),
        ];
    }
}
