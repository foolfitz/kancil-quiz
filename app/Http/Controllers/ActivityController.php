<?php

namespace App\Http\Controllers;

use App\Corpus\SetEditorData;
use App\Games\GameRegistry;
use App\Models\Activity;
use App\Models\Set;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 活動：為題組選遊戲、調整設定、分享（docs/SPEC.md T-08、T-09、T-10）。
 */
class ActivityController extends Controller
{
    public function __construct(private GameRegistry $games) {}

    public function create(Set $set): Response
    {
        Gate::authorize('manage', $set);

        return Inertia::render('activities/Create', [
            'set' => ['id' => $set->id, 'title' => $set->title, 'kind' => $set->kind],
            'content' => SetEditorData::currentContent($set),
            'games' => array_values($this->games->all()),
        ]);
    }

    public function store(Request $request, Set $set): RedirectResponse
    {
        Gate::authorize('manage', $set);

        $data = $request->validate([
            'game_id' => ['required', Rule::in(array_keys($this->games->all()))],
            'options' => ['nullable', 'array'],
        ]);

        $game = $this->games->get($data['game_id']);
        $options = $this->games->withDefaults($game['id'], $data['options'] ?? []);
        if ($errors = $this->games->optionErrors($game['id'], $options)) {
            throw ValidationException::withMessages(['options' => $errors]);
        }

        $entries = count($set->currentRevision?->content()->entries ?? []);
        if ($entries < $game['requires']['minRounds']) {
            throw ValidationException::withMessages([
                'game_id' => "{$game['title']['zh-TW']}至少需要 {$game['requires']['minRounds']} 題，題組目前有 {$entries} 題。",
            ]);
        }

        $activity = $set->activities()->create([
            'game_id' => $game['id'],
            'game_version' => $game['version'],
            'options' => $options,
            'mode' => 'practice',
            'owner_id' => $request->user()->id,
        ]);

        return to_route('activities.show', $activity);
    }

    public function show(Activity $activity): Response
    {
        Gate::authorize('manage', $activity);

        $set = $activity->set;
        $url = route('play', $activity);

        return Inertia::render('activities/Show', [
            'activity' => [
                'id' => $activity->id,
                'game_id' => $activity->game_id,
                'options' => (object) $activity->options,
                'created_at' => $activity->created_at?->toIso8601String(),
                'attempts_count' => $activity->attempts()->count(),
            ],
            'set' => ['id' => $set->id, 'title' => $set->title, 'kind' => $set->kind],
            'content' => SetEditorData::currentContent($set),
            'games' => array_values($this->games->all()),
            'siblings' => $set->activities()->whereKeyNot($activity->id)->latest()->get(['id', 'game_id']),
            'playUrl' => $url,
            'qrSvg' => $this->qrSvg($url),
        ]);
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        Gate::authorize('manage', $activity);

        $activity->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => '活動已刪除']);

        return to_route('sets.edit', $activity->set_id);
    }

    private function qrSvg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }
}
