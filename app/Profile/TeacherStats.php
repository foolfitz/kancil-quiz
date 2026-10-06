<?php

namespace App\Profile;

use App\Models\Activity;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * 創作者頁面的貢獻統計（docs/SPEC.md T-20）：由老師實際的內容算出來，只算已經公開的；都是彙總的次數，
 * 不涉及任何學生。「公開的題組」一律指目前公開、擁有者沒有被停用、沒有刪除的題組（Set::listed()）。
 *
 * - public_sets：這位老師公開的題組數。
 * - entries：這些題組中的題數。
 * - media：這位老師上傳、目前用在任何公開題組（包括別人複製後的題組）中的音檔與圖片數。
 * - copies：其他老師複製這些題組的次數（複製出來、還沒刪除的題組數，不論複製後是否公開）。
 * - activities：其他老師用這些題組或它們的複製品建立、還沒刪除的活動數。
 *   一般老師不能直接用別人的題組建立活動，要先複製（SetPolicy），所以主要是複製後建立的。
 *
 * @phpstan-type Stats array{public_sets: int, entries: int, media: int, copies: int, activities: int}
 */
class TeacherStats
{
    /**
     * @return Stats
     */
    public static function for(User $user): array
    {
        $ids = Set::listed()->where('owner_id', $user->id)->pluck('id');

        return [
            'public_sets' => $ids->count(),
            'entries' => $ids->isEmpty() ? 0 : SetEntry::whereIn('set_id', $ids)->count(),
            'media' => self::mediaInPublicSets($user),
            'copies' => $ids->isEmpty() ? 0 : Set::whereIn('forked_from_id', $ids)->where('owner_id', '!=', $user->id)->count(),
            'activities' => $ids->isEmpty() ? 0 : Activity::where('owner_id', '!=', $user->id)
                ->whereHas('set', fn (Builder $set) => $set->whereIn('id', $ids)->orWhereIn('forked_from_id', $ids))
                ->count(),
        ];
    }

    /**
     * 詞彙組的媒體掛在詞條上（item_media），問答組的在 payload 中以 ID 引用，另外記在對照表 set_entry_media
     * （SetEntry::media()），所以不必把題目讀出來，一句 SQL 就能算。
     */
    private static function mediaInPublicSets(User $user): int
    {
        return Media::where('uploaded_by', $user->id)
            ->where(fn (Builder $query) => $query
                ->whereHas('items.entries.set', fn (Builder $set) => $set->listed())
                ->orWhereHas('entries.set', fn (Builder $set) => $set->listed()))
            ->count();
    }
}
