<?php

namespace App\Http\Controllers;

use App\Games\GameRegistry;
use App\Grading\ActivityResults;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 老師檢視自己活動的作答紀錄與逐題答錯率（docs/SPEC.md T-11）。
 */
class ActivityResultsController extends Controller
{
    public function __construct(private GameRegistry $games) {}

    public function __invoke(Request $request, Activity $activity): Response
    {
        Gate::authorize('manage', $activity);

        $revisions = (new ActivityResults($activity))->revisions();
        $revisionId = collect($revisions)->firstWhere('id', $request->query('revision'))['id'] ?? null;
        $results = new ActivityResults($activity, $revisionId);
        $game = $this->games->find($activity->game_id);
        $set = $activity->set;

        return Inertia::render('activities/Results', [
            'activity' => [
                'id' => $activity->id,
                'game_id' => $activity->game_id,
                'game_title' => $game['title']['zh-TW'] ?? $activity->game_id,
                'scored' => $game['requires']['scored'] ?? true,
            ],
            'set' => ['id' => $set->id, 'title' => $set->title, 'kind' => $set->kind, 'language' => $set->language_code],
            'revisions' => $revisions,
            'revision' => $revisionId,
            'summary' => $results->summary(),
            'questions' => $results->questions(),
            'attempts' => $results->attemptList(),
            // 展開某一次作答時才以 partial reload 取得（?attempt=ID）
            'detail' => function () use ($request, $activity, $results) {
                $id = $request->query('attempt');
                $attempt = is_string($id) ? $activity->attempts()->find($id) : null;

                return $attempt ? $results->attempt($attempt) : null;
            },
        ]);
    }
}
