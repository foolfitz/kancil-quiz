<?php

namespace App\Http\Controllers;

use App\Corpus\ActivityPlayback;
use App\Corpus\SetEditorData;
use App\Curriculum\Textbook;
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
use Illuminate\View\View;
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
        Gate::authorize('createActivity', $set);

        return Inertia::render('activities/Create', [
            'set' => ['id' => $set->id, 'title' => $set->title, 'kind' => $set->kind],
            'content' => SetEditorData::currentContent($set),
            'games' => array_values($this->games->all()),
        ]);
    }

    /**
     * 建立活動之前，以選好的遊戲與設定試玩（docs/SPEC.md T-08）。不建立活動，也不留作答紀錄。
     */
    public function preview(Request $request, Set $set): View
    {
        Gate::authorize('createActivity', $set);

        $game = $this->games->find((string) $request->query('game'));
        abort_if($game === null, 422, '找不到這個遊戲');

        $options = json_decode((string) $request->query('options', '{}'), true);
        abort_unless(is_array($options), 422, '遊戲設定的格式不正確');
        $options = $this->games->withDefaults($game['id'], $options);
        abort_if($this->games->optionErrors($game['id'], $options) !== [], 422, '遊戲設定不正確');

        $revision = $set->currentRevision;
        abort_if($revision === null, 404, '題組還沒有內容');

        $activity = $set->activities()->make([
            'game_id' => $game['id'],
            'game_version' => $game['version'],
            'options' => $options,
            'mode' => 'practice',
        ]);
        $activity->id = $activity->newUniqueId();

        return view('player', [
            'activity' => $activity,
            'preview' => true,
            'playback' => ActivityPlayback::payload($activity, $revision),
        ]);
    }

    public function store(Request $request, Set $set): RedirectResponse
    {
        Gate::authorize('createActivity', $set);

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
            'set' => ['id' => $set->id, 'title' => $set->title, 'kind' => $set->kind, 'url' => $this->setUrl($set)],
            'content' => SetEditorData::currentContent($set),
            'games' => array_values($this->games->all()),
            // 只列同一位老師的活動：教材題組上有其他老師的活動，活動連結本身就是存取憑證（3.4）
            'siblings' => $set->activities()->where('owner_id', $activity->owner_id)->whereKeyNot($activity->id)->latest()->get(['id', 'game_id']),
            'playUrl' => $url,
            'qrSvg' => $this->qrSvg($url),
        ]);
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        Gate::authorize('manage', $activity);

        $activity->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => '活動已刪除']);

        return redirect($activity->set ? $this->setUrl($activity->set) : route('sets.index'));
    }

    /**
     * 活動頁「回到題組」的連結：教材題組回到那一課，其他題組回到編輯頁。
     */
    private function setUrl(Set $set): string
    {
        $lesson = $set->textbookLesson;

        return $lesson !== null ? Textbook::lessonUrl($lesson) : route('sets.edit', $set);
    }

    private function qrSvg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }
}
