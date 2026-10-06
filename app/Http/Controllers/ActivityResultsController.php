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
 * 老師檢視自己活動的作答紀錄與逐題答錯率（docs/SPEC.md T-11），以及每位學生的成績（3.4、M3 驗收）。
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
                // 遊戲得分的名稱（例：打地鼠「星星」）；null 表示這個遊戲不顯示遊戲得分（7.4）
                'score_label' => $this->games->scoreLabel($activity->game_id),
                'require_label' => $activity->requiresLabel(),
            ],
            'set' => ['id' => $set->id, 'title' => $set->title, 'kind' => $set->kind, 'language' => $set->language_code],
            'revisions' => $revisions,
            'revision' => $revisionId,
            // 以 closure 傳入：展開作答明細的 partial reload 不必重算統計
            'summary' => fn () => $results->summary(),
            'questions' => fn () => $results->questions(),
            'students' => fn () => $results->students(),
            'attempts' => fn () => $results->attemptList(),
            // 展開某一次作答時才以 partial reload 取得（?attempt=ID）
            'detail' => function () use ($request, $activity, $results) {
                $id = $request->query('attempt');
                $attempt = is_string($id) ? $activity->attempts()->find($id) : null;

                return $attempt ? $results->attempt($attempt) : null;
            },
        ]);
    }
}
