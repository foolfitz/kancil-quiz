<?php

namespace App\Http\Controllers;

use App\Corpus\SetCopier;
use App\Corpus\SetEditorData;
use App\Corpus\SetRevisionRecorder;
use App\Corpus\SetViewData;
use App\Corpus\SetWriter;
use App\Curriculum\Textbook;
use App\Curriculum\TextbookData;
use App\Games\GameRegistry;
use App\Http\Requests\Sets\SetContentRequest;
use App\Http\Requests\Sets\SetDetailsRequest;
use App\Media\UploadQuota;
use App\Models\Activity;
use App\Models\CurriculumRef;
use App\Models\Language;
use App\Models\Set;
use App\Models\SetEntry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 老師的題組管理（docs/SPEC.md T-04、T-05、T-07）。
 */
class SetController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('sets/Index', [
            'sets' => $request->user()->sets()
                ->with('language')
                ->withCount(['entries', 'activities'])
                ->latest('updated_at')
                ->get()
                ->map(fn (Set $set) => [
                    'id' => $set->id,
                    'kind' => $set->kind,
                    'title' => $set->title,
                    'language' => $set->language->name_zh,
                    'entries_count' => $set->entries_count,
                    'activities_count' => $set->activities_count,
                    'updated_at' => $set->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * 建立題組：自己出題，或從教材挑詞（T-18）。?language=&volume=&lesson= 預先選好教材的某一課。
     */
    public function create(Request $request): Response
    {
        $languages = Language::enabled()->get(['code', 'name_zh', 'name_native']);
        $codes = array_map('strval', $languages->modelKeys());
        $language = in_array($request->query('language'), $codes, true) ? (string) $request->query('language') : ($codes[0] ?? '');

        return Inertia::render('sets/Create', [
            'languages' => $languages,
            'licenses' => Set::LICENSES,
            'language' => $language,
            // 換語言時以 partial reload 重新取得
            'textbook' => fn () => TextbookData::lessons($language),
            'preset' => [
                'volume' => $request->integer('volume') ?: null,
                'lesson' => $request->integer('lesson') ?: null,
            ],
        ]);
    }

    public function store(SetDetailsRequest $request, SetRevisionRecorder $recorder, SetCopier $copier): RedirectResponse
    {
        $data = $request->validated();
        $picked = $this->textbookEntries($data['textbook_entries'] ?? [], $data['kind'], $data['language_code']);

        $set = $request->user()->sets()->create([
            ...Arr::except($data, 'textbook_entries'),
            'faces' => $data['kind'] === 'vocab' ? ['prompt' => ['translation_zh'], 'answer' => ['text']] : null,
        ]);

        if ($picked->isEmpty()) {
            $recorder->record($set, $request->user());
        } else {
            $copier->compose($set, $picked, $request->user());
        }

        return to_route('sets.edit', $set);
    }

    /**
     * 老師挑選的教材詞條，依挑選的順序。只能挑同一種語言的教材題組中的詞，建立的也必須是詞彙組。
     *
     * @param  list<string>  $ids
     * @return EloquentCollection<int, SetEntry>
     */
    private function textbookEntries(array $ids, string $kind, string $language): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        $entries = SetEntry::whereIn('id', $ids)->with('set.textbookLesson')->get()
            ->filter(fn (SetEntry $entry) => $entry->item_id !== null && $entry->set?->isTextbook() && $entry->set->language_code === $language)
            ->sortBy(fn (SetEntry $entry) => array_search($entry->id, $ids, true))
            ->values();

        if ($kind !== 'vocab' || $entries->count() !== count($ids)) {
            throw ValidationException::withMessages(['textbook_entries' => '只能從同一種語言的教材挑詞，建立詞彙組。']);
        }

        return $entries;
    }

    /**
     * 唯讀檢視：自己的題組、共備庫中的公開題組，或審核者負責語言的題組。
     */
    public function show(Request $request, Set $set): Response
    {
        Gate::authorize('view', $set);

        return Inertia::render('sets/Show', SetViewData::props($set, $request->user()));
    }

    /**
     * 擁有者編輯題組；負責該語言的審核者也可以修正已公開的題組（C-03），修改記在題組版本中。
     */
    public function edit(Request $request, Set $set, GameRegistry $games): Response
    {
        Gate::authorize('edit', $set);

        return Inertia::render('sets/Edit', [
            'set' => [
                'id' => $set->id,
                'kind' => $set->kind,
                'title' => $set->title,
                'description' => $set->description,
                'language_code' => $set->language_code,
                'license' => $set->license,
                'faces' => $set->faces,
                'owner' => $set->owner->name,
                'tags' => $set->tags ?? [],
                'curriculum_ref_ids' => $set->curriculumRefs()->pluck('curriculum_refs.id'),
                'revision' => $set->currentRevision?->number,
                // 教材題組（3.6）：審核者在這裡修正後，重新匯入時會略過這一課
                'textbook_url' => $set->textbookLesson ? Textbook::lessonUrl($set->textbookLesson) : null,
            ],
            'entries' => SetEditorData::entries($set, $request->user()),
            'can' => [
                'manage' => Gate::allows('manage', $set),
                'export' => $set->current_revision_id !== null && Gate::allows('export', $set),
            ],
            // 分享與公開（T-17、T-12），只有擁有者看得到
            'sharing' => Gate::allows('manage', $set) ? [
                'visibility' => $set->visibility,
                'review_status' => $set->review_status,
                'share_url' => $set->share_token ? route('sets.shared', $set->share_token) : null,
                'last_review' => $set->reviews()->with('user:id,name')->first()?->only(['action', 'note', 'created_at']),
            ] : null,
            'activities' => Gate::allows('manage', $set)
                ? $set->activities()->latest()->get(['id', 'game_id'])->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'game' => $games->title($activity->game_id),
                ])
                : [],
            'languages' => Language::enabled()->get(['code', 'name_zh', 'name_native']),
            // 教材題組的授權不在老師的選項中，編輯時仍要列出
            'licenses' => array_values(array_unique([...Set::LICENSES, $set->license])),
            'curriculumRefs' => CurriculumRef::query()
                ->whereIn('language_code', Language::enabled()->pluck('code'))
                ->orderBy('volume')
                ->orderBy('lesson')
                ->get(['id', 'language_code', 'volume', 'lesson', 'title_zh', 'title_native']),
            // 「已上傳多少／上限」（第 9 節）：算的是上傳的人，審核者修正別人的題組時也是自己的用量
            'uploadQuota' => UploadQuota::of($request->user())->toArray(),
        ]);
    }

    public function update(SetContentRequest $request, Set $set, SetWriter $writer): RedirectResponse
    {
        Gate::authorize('edit', $set);

        $data = $request->validated();
        if (Gate::denies('manage', $set) && $data['language_code'] !== $set->language_code) {
            throw ValidationException::withMessages(['language_code' => '審核者不能更改題組的語言。']);
        }
        $tags = array_values(array_unique(array_filter(array_map('trim', $data['tags'] ?? []))));
        $set->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'language_code' => $data['language_code'],
            'license' => $data['license'],
            'tags' => $tags ?: null,
        ]);
        $set->curriculumRefs()->sync($data['curriculum_ref_ids'] ?? []);
        $writer->write($set, $data, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => '題組已儲存']);

        return to_route('sets.edit', $set);
    }

    public function destroy(Set $set): RedirectResponse
    {
        Gate::authorize('manage', $set);

        $set->activities()->delete();
        $set->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => '題組已刪除']);

        return to_route('sets.index');
    }
}
