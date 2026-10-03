<?php

namespace App\Http\Controllers;

use App\Corpus\SetEditorData;
use App\Corpus\SetRevisionRecorder;
use App\Corpus\SetViewData;
use App\Corpus\SetWriter;
use App\Http\Requests\Sets\SetContentRequest;
use App\Http\Requests\Sets\SetDetailsRequest;
use App\Models\CurriculumRef;
use App\Models\Language;
use App\Models\Set;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    public function create(): Response
    {
        return Inertia::render('sets/Create', [
            'languages' => Language::enabled()->get(['code', 'name_zh', 'name_native']),
            'licenses' => SetDetailsRequest::LICENSES,
        ]);
    }

    public function store(SetDetailsRequest $request, SetRevisionRecorder $recorder): RedirectResponse
    {
        $data = $request->validated();
        $set = $request->user()->sets()->create([
            ...$data,
            'faces' => $data['kind'] === 'vocab' ? ['prompt' => ['translation_zh'], 'answer' => ['text']] : null,
        ]);
        $recorder->record($set, $request->user());

        return to_route('sets.edit', $set);
    }

    /**
     * 唯讀檢視：自己的題組、共備庫中的公開題組，或審核者負責語言的題組。
     */
    public function show(Request $request, Set $set): Response
    {
        Gate::authorize('view', $set);

        return Inertia::render('sets/Show', SetViewData::props($set, $request->user()));
    }

    public function edit(Set $set): Response
    {
        Gate::authorize('manage', $set);

        return Inertia::render('sets/Edit', [
            'set' => [
                'id' => $set->id,
                'kind' => $set->kind,
                'title' => $set->title,
                'description' => $set->description,
                'language_code' => $set->language_code,
                'license' => $set->license,
                'faces' => $set->faces,
                'tags' => $set->tags ?? [],
                'curriculum_ref_ids' => $set->curriculumRefs()->pluck('curriculum_refs.id'),
                'revision' => $set->currentRevision?->number,
            ],
            'entries' => SetEditorData::entries($set),
            'activities' => $set->activities()->latest()->get(['id', 'game_id', 'created_at']),
            'languages' => Language::enabled()->get(['code', 'name_zh', 'name_native']),
            'licenses' => SetDetailsRequest::LICENSES,
            'curriculumRefs' => CurriculumRef::query()
                ->whereIn('language_code', Language::enabled()->pluck('code'))
                ->orderBy('volume')
                ->orderBy('lesson')
                ->get(['id', 'language_code', 'volume', 'lesson', 'title_zh']),
        ]);
    }

    public function update(SetContentRequest $request, Set $set, SetWriter $writer): RedirectResponse
    {
        Gate::authorize('manage', $set);

        $data = $request->validated();
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
