<?php

namespace App\Grading;

use App\Corpus\EntryFaces;
use App\Corpus\MediaUrls;
use App\Games\GameRegistry;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\AttemptResponse;
use App\Models\SetRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * 活動的作答結果（docs/SPEC.md T-11）：全班的逐題答錯率，以及每次作答依當時的題組版本
 * 顯示題目與對錯（3.3）。對錯一律用伺服器的判定，每題以第一筆作答計算（7.4）。
 *
 * @phpstan-import-type Face from EntryFaces
 *
 * @phpstan-type AttemptRow array{id: string, player_label: string|null, started_at: string, completed_at: string|null, correct_count: int|null, round_count: int, game_score: int|null, duration_ms: int|null, revision_number: int}
 */
class ActivityResults
{
    /** @var array<string, stdClass> 版本內容（媒體為絕對網址），以版本 ID 為鍵 */
    private array $contents = [];

    private ?string $shape = null;

    /**
     * @param  string|null  $revisionId  只看某個版本的作答；null 表示全部
     */
    public function __construct(private Activity $activity, private ?string $revisionId = null) {}

    /**
     * 有作答紀錄的題組版本，新的在前。
     *
     * @return list<array{id: string, number: int, attempts_count: int, current: bool}>
     */
    public function revisions(): array
    {
        $counts = Attempt::query()
            ->where('activity_id', $this->activity->id)
            ->groupBy('set_revision_id')
            ->selectRaw('set_revision_id, COUNT(*) AS attempts_count');

        $revisions = SetRevision::query()
            ->joinSub($counts, 'counts', 'counts.set_revision_id', '=', 'set_revisions.id')
            ->orderByDesc('number')
            ->get(['set_revisions.id', 'set_revisions.number', 'counts.attempts_count']);

        return array_values($revisions->map(fn (SetRevision $revision) => [
            'id' => $revision->id,
            'number' => (int) $revision->getAttribute('number'),
            'attempts_count' => (int) $revision->getAttribute('attempts_count'),
            'current' => $revision->id === $this->activity->set->current_revision_id,
        ])->all());
    }

    /**
     * @return array{attempts: int, completed: int, average_rate: float|null}
     */
    public function summary(): array
    {
        $completed = $this->attempts()->whereNotNull('completed_at');
        $rate = (clone $completed)
            ->whereNotNull('correct_count')
            ->where('round_count', '>', 0)
            ->selectRaw('AVG(1.0 * correct_count / round_count) AS rate')
            ->value('rate');

        return [
            'attempts' => $this->attempts()->count(),
            'completed' => $completed->count(),
            'average_rate' => $rate === null ? null : round((float) $rate, 4),
        ];
    }

    /**
     * 逐題統計。題目依最新一個有作答的版本排列，題目文字也取自該版本；
     * 只出現在舊版本中的題目（之後被刪除）排在後面。
     *
     * @return list<array{entry_id: string, question: Face, answer: Face|null, responses: int, answered: int, wrong: int, revisions: list<int>, changed: bool, common_mistake: array{face: Face, count: int}|null}>
     */
    public function questions(): array
    {
        $firstResponses = DB::table('attempt_responses')
            ->join('attempts', 'attempts.id', '=', 'attempt_responses.attempt_id')
            ->where('attempts.activity_id', $this->activity->id)
            ->when($this->revisionId, fn ($query, $id) => $query->where('attempts.set_revision_id', $id))
            ->groupBy('attempt_responses.attempt_id', 'attempt_responses.entry_id')
            ->selectRaw('MIN(attempt_responses.id)');

        // 同一題、同一版本、同樣的選擇合併成一列，資料量只跟題數有關，不跟作答次數成正比。
        $rows = DB::table('attempt_responses')
            ->join('attempts', 'attempts.id', '=', 'attempt_responses.attempt_id')
            ->whereIn('attempt_responses.id', $firstResponses)
            ->groupBy('attempt_responses.entry_id', 'attempts.set_revision_id', 'attempt_responses.correct', 'attempt_responses.selected')
            ->get([
                'attempt_responses.entry_id',
                'attempts.set_revision_id',
                'attempt_responses.correct',
                'attempt_responses.selected',
                DB::raw('COUNT(*) AS n'),
            ]);

        $revisions = array_values(array_filter(
            $this->revisions(),
            fn (array $revision) => $this->revisionId === null || $revision['id'] === $this->revisionId,
        ));
        if ($revisions === []) {
            return [];
        }

        /** @var array<string, array{responses: int, answered: int, wrong: int, mistakes: array<string, int>}> $stats */
        $stats = [];
        foreach ($rows as $row) {
            $count = (int) $row->n;
            $stat = $stats[$row->entry_id] ?? ['responses' => 0, 'answered' => 0, 'wrong' => 0, 'mistakes' => []];
            $stat['responses'] += $count;
            if ($row->correct !== null) {
                $stat['answered'] += $count;
                if (! (bool) $row->correct) {
                    $stat['wrong'] += $count;
                    $choice = json_decode((string) $row->selected, true)[0] ?? null;
                    if (is_string($choice)) {
                        $key = "{$row->set_revision_id}\n{$choice}";
                        $stat['mistakes'][$key] = ($stat['mistakes'][$key] ?? 0) + $count;
                    }
                }
            }
            $stats[$row->entry_id] = $stat;
        }

        // 題目依版本由新到舊收集：新版本的題目在前，文字也以新版本為準。
        /** @var array<string, list<array{number: int, set: stdClass, entry: stdClass}>> $versions */
        $versions = [];
        foreach ($revisions as $revision) {
            $set = $this->content($revision['id']);
            foreach ($set->entries as $entry) {
                if ($revision === $revisions[0] || isset($stats[$entry->id])) {
                    $versions[$entry->id][] = ['number' => $revision['number'], 'set' => $set, 'entry' => $entry];
                }
            }
        }

        $questions = [];
        foreach ($versions as $entryId => $found) {
            $newest = $found[0];
            $stat = $stats[$entryId] ?? ['responses' => 0, 'answered' => 0, 'wrong' => 0, 'mistakes' => []];
            $bodies = array_unique(array_map(fn (array $version) => json_encode($version['entry']), $found));

            $questions[] = [
                'entry_id' => $entryId,
                'question' => EntryFaces::question($newest['set'], $newest['entry']),
                'answer' => EntryFaces::answer($newest['set'], $newest['entry']),
                'responses' => $stat['responses'],
                'answered' => $stat['answered'],
                'wrong' => $stat['wrong'],
                'revisions' => array_map(fn (array $version) => $version['number'], $found),
                'changed' => count($bodies) > 1,
                'common_mistake' => $this->commonMistake($entryId, $stat['mistakes']),
            ];
        }

        return $questions;
    }

    /**
     * 作答紀錄，新的在前。
     *
     * @return LengthAwarePaginator<int, AttemptRow>
     */
    public function attemptList(int $perPage = 30): LengthAwarePaginator
    {
        return $this->attempts()
            ->with('revision:id,number')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Attempt $attempt) => [
                'id' => $attempt->id,
                'player_label' => $attempt->player_label,
                'started_at' => $attempt->started_at->toIso8601String(),
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                'correct_count' => $attempt->correct_count,
                'round_count' => $attempt->round_count,
                'game_score' => $attempt->game_score,
                'duration_ms' => $attempt->duration_ms,
                'revision_number' => (int) $attempt->revision->getAttribute('number'),
            ]);
    }

    /**
     * 一次作答的逐題明細，依作答當時的題組版本顯示（docs/SPEC.md 3.3、M2 驗收 4）。
     *
     * @return array{id: string, revision_number: int, rounds: list<array{entry_id: string, question: Face, answer: Face|null, selected: Face|null, correct: bool|null, answered: bool, tries: int}>}
     */
    public function attempt(Attempt $attempt): array
    {
        $set = $this->content($attempt->set_revision_id);
        $responses = $attempt->responses()->get()->groupBy('entry_id');

        $rounds = [];
        foreach ($set->entries as $entry) {
            $mine = $responses->get($entry->id);
            /** @var AttemptResponse|null $first */
            $first = $mine?->first();
            $choice = $first?->selected[0] ?? null;

            $rounds[] = [
                'entry_id' => $entry->id,
                'question' => EntryFaces::question($set, $entry),
                'answer' => EntryFaces::answer($set, $entry),
                'selected' => $choice === null ? null : EntryFaces::choice($set, $choice, $this->shape()),
                'correct' => $first?->correct,
                'answered' => $first !== null,
                'tries' => $mine?->count() ?? 0,
            ];
        }

        return [
            'id' => $attempt->id,
            'revision_number' => (int) $attempt->revision->getAttribute('number'),
            'rounds' => $rounds,
        ];
    }

    /**
     * @return Builder<Attempt>
     */
    private function attempts(): Builder
    {
        return Attempt::query()
            ->where('activity_id', $this->activity->id)
            ->when($this->revisionId, fn ($query, $id) => $query->where('set_revision_id', $id));
    }

    /**
     * 活動的遊戲使用的題目形狀：配對時學生選的是另一題的右側卡片（7.4）。
     */
    private function shape(): string
    {
        return $this->shape ??= app(GameRegistry::class)->find($this->activity->game_id)['requires']['shape'] ?? 'mcq';
    }

    private function content(string $revisionId): stdClass
    {
        return $this->contents[$revisionId] ??= MediaUrls::absolutize(
            SetRevision::findOrFail($revisionId)->content(),
        );
    }

    /**
     * 最多人選的錯誤答案。不同版本中的同一個答案（文字與圖片都相同）合併計算。
     *
     * @param  array<string, int>  $mistakes  以「版本 ID\n選項 ID」為鍵
     * @return array{face: Face, count: int}|null
     */
    private function commonMistake(string $entryId, array $mistakes): ?array
    {
        $merged = [];
        foreach ($mistakes as $key => $count) {
            [$revisionId, $choice] = explode("\n", $key, 2);
            $face = EntryFaces::choice($this->content($revisionId), $choice, $this->shape())
                ?? ['text' => null, 'note' => null, 'image' => null, 'audio' => null];
            $label = json_encode($face, JSON_THROW_ON_ERROR);
            $merged[$label] = ['face' => $face, 'count' => ($merged[$label]['count'] ?? 0) + $count];
        }

        usort($merged, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $merged[0] ?? null;
    }
}
