<?php

namespace App\Http\Controllers;

use App\Games\GameRegistry;
use App\Grading\ActivityResults;
use App\Models\Activity;
use App\Support\ActivitySettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 每位學生的成績下載成 CSV（docs/SPEC.md 3.4），方便老師抄進自己的紀錄。
 * 欄位與結果頁的「每位學生」相同；可以用 ?revision= 只看某個版本，與結果頁一致。
 */
class ActivityResultsCsvController extends Controller
{
    public function __construct(private GameRegistry $games) {}

    public function __invoke(Request $request, Activity $activity): StreamedResponse
    {
        Gate::authorize('manage', $activity);

        $revisions = (new ActivityResults($activity))->revisions();
        $revisionId = collect($revisions)->firstWhere('id', $request->query('revision'))['id'] ?? null;
        $students = (new ActivityResults($activity, $revisionId))->students();
        $scored = $this->games->find($activity->game_id)['requires']['scored'] ?? true;
        $title = $this->games->title($activity->game_id);

        $header = $scored
            ? ['名字或座號', '答對（第一次玩完）', '題數', '最高答對', '玩了幾次', '玩完幾次', '最後作答時間']
            : ['名字或座號', '玩了幾次', '玩完幾次', '最後作答時間'];

        $rows = array_map(function (array $student) use ($scored) {
            $counted = $student['counted'];
            $last = ActivitySettings::format(CarbonImmutable::parse($student['last_at']));
            $last = $last === null ? '' : str_replace('T', ' ', $last);

            return $scored
                ? [
                    $student['label'],
                    $counted['completed_at'] === null ? '' : $counted['correct_count'],
                    $counted['round_count'],
                    $student['best']['correct_count'] ?? '',
                    count($student['attempts']),
                    $student['completed'],
                    $last,
                ]
                : [$student['label'], count($student['attempts']), $student['completed'], $last];
        }, $students);

        $name = "作答結果-{$activity->set->title}-{$title}.csv";

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // BOM：Excel 才會用 UTF-8 開啟，中文不會變亂碼
            fwrite($out, "\u{FEFF}");
            foreach ([$header, ...$rows] as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }
            fclose($out);
        }, str_replace(['/', '\\'], '-', $name), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * 名字是學生輸入的：開頭是 = + - @ 的儲存格，試算表會當成公式執行，前面加上 ' 讓它保持文字。
     */
    private static function cell(string|int|null $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }
}
