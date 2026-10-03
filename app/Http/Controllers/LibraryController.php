<?php

namespace App\Http\Controllers;

use App\Models\CurriculumRef;
use App\Models\Language;
use App\Models\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 共備庫（docs/SPEC.md T-13）：依語言、冊、課、標籤瀏覽與搜尋公開的題組，複製後改編。
 */
class LibraryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'language' => ['nullable', 'string', 'max:8'],
            'volume' => ['nullable', 'integer', 'min:1'],
            'lesson' => ['nullable', 'integer', 'min:1'],
            'tag' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $languages = Language::enabled()->get(['code', 'name_zh', 'name_native']);
        $codes = array_values(array_map('strval', $languages->modelKeys()));

        $sets = $this->publicSets($codes)
            ->when($filters['language'] ?? null, fn (Builder $query, string $code) => $query->where('language_code', $code))
            ->when(isset($filters['volume']) || isset($filters['lesson']), fn (Builder $query) => $query->whereHas(
                'curriculumRefs',
                fn (Builder $refs) => $refs
                    ->when($filters['volume'] ?? null, fn (Builder $refs, int $volume) => $refs->where('volume', $volume))
                    ->when($filters['lesson'] ?? null, fn (Builder $refs, int $lesson) => $refs->where('lesson', $lesson)),
            ))
            ->when($filters['tag'] ?? null, fn (Builder $query, string $tag) => $query->whereJsonContains('tags', $tag))
            ->when($filters['q'] ?? null, function (Builder $query, string $q) {
                // 標題、說明，以及詞彙組中的目標語與中文
                $like = "%{$q}%";
                $query->where(fn (Builder $query) => $query
                    ->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('entries.item', fn (Builder $items) => $items
                        ->where('text', 'like', $like)
                        ->orWhere('translation_zh', 'like', $like)));
            })
            ->with(['owner:id,name', 'language', 'curriculumRefs'])
            ->withCount('entries')
            ->latest('updated_at')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Set $set) => [
                'id' => $set->id,
                'kind' => $set->kind,
                'title' => $set->title,
                'description' => $set->description,
                'language' => $set->language->name_zh,
                'owner' => $set->owner->name,
                'entries_count' => $set->entries_count,
                'curriculum' => $set->curriculumRefs->map(fn (CurriculumRef $ref) => ['volume' => $ref->volume, 'lesson' => $ref->lesson])->all(),
                'tags' => $set->tags ?? [],
                'forked' => $set->forked_from_id !== null,
                'updated_at' => $set->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('library/Index', [
            'sets' => $sets,
            'filters' => (object) array_filter($filters, fn ($value) => $value !== null),
            'languages' => $languages,
            'curriculumRefs' => CurriculumRef::query()
                ->whereIn('language_code', $codes)
                ->orderBy('volume')
                ->orderBy('lesson')
                ->get(['id', 'language_code', 'volume', 'lesson', 'title_zh']),
            'tags' => $this->popularTags($codes),
        ]);
    }

    /**
     * @param  list<string>  $languages  開放的語言（docs/SPEC.md 1.4）
     * @return Builder<Set>
     */
    private function publicSets(array $languages): Builder
    {
        return Set::query()
            ->where('visibility', 'public')
            ->whereIn('language_code', $languages);
    }

    /**
     * 公開題組中最常用的標籤。
     *
     * @param  list<string>  $languages
     * @return list<string>
     */
    private function popularTags(array $languages): array
    {
        $counts = [];
        foreach ($this->publicSets($languages)->whereNotNull('tags')->pluck('tags') as $tags) {
            foreach ((array) $tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_map('strval', array_keys(array_slice($counts, 0, 30, true)));
    }
}
