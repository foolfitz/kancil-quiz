<?php

namespace App\Profile;

use App\Models\Contribution;
use App\Models\Set;
use App\Models\SetReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 創作者頁面的貢獻日曆（docs/SPEC.md T-20）：像 GitHub 的 contribution graph，最近一年每一天（台灣時間）
 * 的貢獻次數。貢獻由老師實際的活動算出來，不是老師自己填的，而且只算已經公開的內容：
 *
 * - 修改：為題組產生版本（建立、編輯、修改署名都算，App\Corpus\SetRevisionRecorder），只算目前公開、
 *   擁有者沒有被停用的題組（Set::listed()）。公開前的修改也算在內，和 GitHub 一樣：題組公開後，
 *   修訂紀錄本來就看得到。審核者修正別人的公開題組（C-03）也算審核者的貢獻。
 *   題組版本 30 天後可能被清除（第 5 節），所以每天的次數另外累計在 contributions（一位老師、
 *   一個題組、一天一列）；題組被刪除、下架或擁有者被停用時，它的貢獻就不再顯示。
 * - 公開：自己的題組通過審核的那一天（set_reviews 的 approved），同樣只算目前公開的題組。
 *
 * @phpstan-type Day array{revisions: int, published: int}
 * @phpstan-type Calendar array{from: string, to: string, total: int, days: array<string, Day>}
 */
class Contributions
{
    /**
     * 最近一年：從今天往前 52 週那一週的星期日開始，到今天。
     */
    public const WEEKS = 52;

    /**
     * 題組產生新版本時累計一次（在 SetRevisionRecorder 的交易中呼叫）。
     */
    public static function record(Set $set, User $by, ?CarbonInterface $at = null): void
    {
        DB::table('contributions')->upsert(
            [[
                'user_id' => $by->id,
                'set_id' => $set->id,
                'date' => self::date($at ?? CarbonImmutable::now()),
                'revisions' => 1,
            ]],
            ['user_id', 'set_id', 'date'],
            ['revisions' => DB::raw('revisions + 1')],
        );
    }

    /**
     * @return Calendar days 只列有貢獻的日子，以日期（YYYY-MM-DD）為鍵
     */
    public static function calendar(User $user, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->setTimezone(self::timezone())->startOfDay();
        $from = $today->subWeeks(self::WEEKS)->startOfWeek(CarbonInterface::SUNDAY);

        $days = [];
        $revisions = Contribution::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $today->toDateString()])
            ->whereHas('set', fn (Builder $set) => $set->listed())
            ->groupBy('date')
            ->selectRaw('date, SUM(revisions) AS total')
            ->pluck('total', 'date');
        foreach ($revisions as $date => $total) {
            $days[(string) $date] = ['revisions' => (int) $total, 'published' => 0];
        }

        // 公開的事件很少，直接取出時間、在 PHP 換成台灣時間的日期
        $published = SetReview::query()
            ->where('action', 'approved')
            ->whereHas('set', fn (Builder $set) => $set->listed()->where('owner_id', $user->id))
            ->whereBetween('created_at', [$from->utc(), $today->endOfDay()->utc()])
            ->pluck('created_at');
        foreach ($published as $at) {
            $date = self::date($at instanceof CarbonInterface ? $at : CarbonImmutable::parse((string) $at, 'UTC'));
            $days[$date] ??= ['revisions' => 0, 'published' => 0];
            $days[$date]['published']++;
        }
        ksort($days);

        return [
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'total' => array_sum(array_map(fn (array $day) => $day['revisions'] + $day['published'], $days)),
            'days' => $days,
        ];
    }

    /**
     * 台灣時間的日期（YYYY-MM-DD）。
     */
    public static function date(CarbonInterface $at): string
    {
        return $at->toImmutable()->setTimezone(self::timezone())->toDateString();
    }

    private static function timezone(): string
    {
        return (string) config('kancil.timezone');
    }
}
