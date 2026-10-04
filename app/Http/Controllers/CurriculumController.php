<?php

namespace App\Http\Controllers;

use App\Corpus\SetEditorData;
use App\Curriculum\Textbook;
use App\Curriculum\TextbookData;
use App\Games\GameRegistry;
use App\Models\Activity;
use App\Models\CurriculumRef;
use App\Models\Language;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 教材（docs/SPEC.md T-18）：依語言、冊、課瀏覽教材的詞彙，直接用教材題組建立活動，
 * 或複製、挑詞做成自己的題組。
 */
class CurriculumController extends Controller
{
    public function index(Request $request): Response
    {
        $languages = Language::enabled()->get(['code', 'name_zh', 'name_native']);
        $codes = array_map('strval', $languages->modelKeys());
        // 沒有指定語言時，選第一個有教材的語言
        $language = in_array($request->query('language'), $codes, true)
            ? (string) $request->query('language')
            : (CurriculumRef::whereIn('language_code', $codes)->whereNotNull('set_id')->orderBy('id')->value('language_code') ?? $codes[0] ?? '');

        return Inertia::render('curriculum/Index', [
            'languages' => $languages,
            'language' => $language,
            'lessons' => TextbookData::lessons($language),
        ]);
    }

    public function show(Request $request, string $language, int $volume, int $lesson, GameRegistry $games): Response
    {
        $ref = CurriculumRef::query()
            ->where(['language_code' => $language, 'volume' => $volume, 'lesson' => $lesson])
            ->whereHas('language', fn ($query) => $query->where('enabled', true))
            ->with('language')
            ->firstOrFail();
        $set = $ref->set;
        $user = $request->user();

        return Inertia::render('curriculum/Lesson', [
            'lesson' => [
                'language' => ['code' => $ref->language_code, 'name_zh' => $ref->language->name_zh],
                'volume' => $ref->volume,
                'lesson' => $ref->lesson,
                'title_zh' => $ref->title_zh,
                'title_native' => $ref->title_native,
            ],
            'set' => $set === null ? null : [
                'id' => $set->id,
                'title' => $set->title,
                'description' => $set->description,
                'license' => $set->license,
                'authors' => array_column($set->effectiveAuthors(), 'name'),
                'can' => [
                    'activity' => Gate::allows('createActivity', $set),
                    'copy' => Gate::allows('copy', $set),
                    'edit' => Gate::allows('edit', $set),
                    'export' => $set->current_revision_id !== null && Gate::allows('export', $set),
                ],
            ],
            'words' => $set === null ? [] : SetEditorData::entries($set),
            'imageCredits' => $set === null ? [] : $this->imageCredits($set),
            // 自己用這一課建立的活動；其他老師的活動不列出（活動連結本身就是存取憑證，3.4）
            'activities' => $set === null ? [] : $set->activities()->where('owner_id', $user->id)->withCount('attempts')->latest()->get()
                ->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'game' => $games->title($activity->game_id),
                    'attempts_count' => $activity->attempts_count,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ]),
            'mySets' => $user->sets()->whereHas('curriculumRefs', fn ($query) => $query->whereKey($ref->id))->latest('updated_at')->get(['id', 'title']),
            // 共備庫中對應這一課的題組
            'shared' => Set::query()
                ->where('visibility', 'public')
                ->whereHas('curriculumRefs', fn ($query) => $query->whereKey($ref->id))
                ->when($set, fn ($query) => $query->whereKeyNot($set->id))
                ->with('owner:id,name')
                ->withCount('entries')
                ->latest('updated_at')
                ->limit(12)
                ->get()
                ->map(fn (Set $shared) => [
                    'id' => $shared->id,
                    'kind' => $shared->kind,
                    'title' => $shared->title,
                    'owner' => $shared->owner->name,
                    'entries_count' => $shared->entries_count,
                ]),
            'libraryUrl' => route('library', ['language' => $ref->language_code, 'volume' => $ref->volume, 'lesson' => $ref->lesson]),
            'title' => Textbook::title($ref),
        ]);
    }

    /**
     * 插圖的署名，例：Kancil Quiz，AI 生成（CC-BY-4.0）。相同的署名只列一次。
     *
     * @return list<string>
     */
    private function imageCredits(Set $set): array
    {
        return array_values($set->loadMissing('entries.item.media')->entries
            ->flatMap(fn (SetEntry $entry) => $entry->item->media ?? [])
            ->filter(fn (Media $media) => $media->kind === 'image')
            ->map(fn (Media $media) => implode('，', array_filter([
                implode('、', array_column($media->authors ?? [], 'name')),
                $media->source,
            ])).($media->license ? "（{$media->license}）" : ''))
            ->filter(fn (string $credit) => $credit !== '')
            ->unique()
            ->all());
    }
}
