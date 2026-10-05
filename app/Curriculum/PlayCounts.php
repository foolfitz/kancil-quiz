<?php

namespace App\Curriculum;

use App\Games\GameRegistry;
use App\Models\CurriculumRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 教材的人氣統計（docs/SPEC.md S-06、A-04）。
 *
 * - 試玩：訪客不經過活動玩一課，播放器在按下「開始」與玩完時各送一次計次（同一個分頁只算第一次），
 *   這裡依台灣時間的日期累計到 curriculum_plays，不存逐筆紀錄。
 * - 課堂：老師直接用教材題組建立的活動，從作答紀錄計算（只到作答的保存期限，第 5 節）。
 *   複製或挑詞做成的題組不算在這一課。
 *
 * @phpstan-type Row array{language: string, volume: int, lesson: int, title: string, url: string, game: string, trial_starts: int, trial_finishes: int, class_starts: int, class_finishes: int}
 */
final class PlayCounts
{
    public const EVENTS = ['start' => 'starts', 'finish' => 'finishes'];

    public function __construct(private GameRegistry $games) {}

    /**
     * @param  'start'|'finish'  $event
     */
    public static function record(CurriculumRef $ref, string $game, string $event): void
    {
        $column = self::EVENTS[$event];

        DB::table('curriculum_plays')->upsert(
            [[
                'curriculum_ref_id' => $ref->id,
                'game_id' => $game,
                'date' => self::today()->toDateString(),
                'starts' => (int) ($column === 'starts'),
                'finishes' => (int) ($column === 'finishes'),
            ]],
            ['curriculum_ref_id', 'game_id', 'date'],
            [$column => DB::raw("{$column} + 1")],
        );
    }

    /**
     * 台灣時間的今天 00:00。
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('kancil.timezone'))->startOfDay();
    }

    /**
     * 每課、每個遊戲的次數，試玩與課堂分開。
     *
     * @param  int|null  $days  最近幾天（含今天）；null 表示全部
     * @return Collection<string, Row> 以「課的 ID:遊戲」為鍵
     */
    public function summary(?int $days = null, ?string $language = null): Collection
    {
        $since = $days === null ? null : self::today()->subDays($days - 1);

        $trial = DB::table('curriculum_plays')
            ->join('curriculum_refs', 'curriculum_refs.id', '=', 'curriculum_plays.curriculum_ref_id')
            ->when($since, fn ($query) => $query->where('curriculum_plays.date', '>=', $since->toDateString()))
            ->when($language, fn ($query) => $query->where('curriculum_refs.language_code', $language))
            ->groupBy('curriculum_plays.curriculum_ref_id', 'curriculum_plays.game_id')
            ->select('curriculum_plays.curriculum_ref_id', 'curriculum_plays.game_id')
            ->selectRaw('sum(curriculum_plays.starts) as starts, sum(curriculum_plays.finishes) as finishes')
            ->get();

        $classroom = DB::table('attempts')
            ->join('activities', 'activities.id', '=', 'attempts.activity_id')
            ->join('curriculum_refs', 'curriculum_refs.set_id', '=', 'activities.set_id')
            // started_at 存 UTC
            ->when($since, fn ($query) => $query->where('attempts.started_at', '>=', $since->setTimezone(config('app.timezone'))))
            ->when($language, fn ($query) => $query->where('curriculum_refs.language_code', $language))
            ->groupBy('curriculum_refs.id', 'activities.game_id')
            ->select('curriculum_refs.id as curriculum_ref_id', 'activities.game_id')
            ->selectRaw('count(*) as starts, count(attempts.completed_at) as finishes')
            ->get();

        $refs = CurriculumRef::with('language')
            ->whereIn('id', $trial->pluck('curriculum_ref_id')->merge($classroom->pluck('curriculum_ref_id'))->unique())
            ->get()
            ->keyBy('id');

        /** @var array<string, Row> $rows */
        $rows = [];
        foreach (['trial' => $trial, 'class' => $classroom] as $source => $counts) {
            foreach ($counts as $count) {
                $ref = $refs->get($count->curriculum_ref_id);
                if ($ref === null) {
                    continue;
                }
                $key = "{$ref->id}:{$count->game_id}";
                $rows[$key] ??= [
                    'language' => $ref->language->name_zh ?? $ref->language_code,
                    'volume' => $ref->volume,
                    'lesson' => $ref->lesson,
                    'title' => Textbook::title($ref),
                    'url' => Textbook::lessonUrl($ref),
                    'game' => $this->games->title($count->game_id),
                    'trial_starts' => 0,
                    'trial_finishes' => 0,
                    'class_starts' => 0,
                    'class_finishes' => 0,
                ];
                $rows[$key]["{$source}_starts"] = (int) $count->starts;
                $rows[$key]["{$source}_finishes"] = (int) $count->finishes;
            }
        }

        return collect($rows);
    }
}
