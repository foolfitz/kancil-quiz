<?php

namespace App\Support;

use App\Corpus\MediaUrls;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Contribution;
use App\Models\CurriculumRef;
use App\Models\Item;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetReview;
use App\Models\SetRevision;
use App\Profile\Contributions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 清除過了保存期限的資料（docs/SPEC.md 第 5、9 節），由排程每天執行 kancil:prune。
 * 期限在 config/kancil.php 的 retention。依序清除，前面刪掉的資料不再引用後面的，所以一次就能清乾淨：
 *
 * 1. 作答紀錄：開始作答超過保存期限（預設 12 個月）。
 * 2. 貢獻紀錄（創作者頁面的貢獻日曆每天的版本數，App\Profile\Contributions）：日期（台灣時間）超過 13 個月。
 *    日曆只顯示最近 52 週加上這一週，更早的用不到。
 * 3. 老師刪除的活動、題組與詞條：刪除超過 30 天，連同活動的作答紀錄、題組的內容、版本與貢獻紀錄。
 * 4. 題組版本：不是最新版本、沒有作答或審核紀錄引用，而且被新版本取代超過 30 天。
 *    教材題組的版本不清除：「審核者修正過」要看匯入之後的版本（CurriculumImporter::editedOnSite()）。
 * 5. 媒體：沒有詞條（item_media）、問答題（set_entry_media）或題組版本（content 的 JSON）引用，
 *    而且上傳超過 7 天（老師可能還沒按儲存）。
 * 6. 媒體目錄中沒有對應資料的檔案（例如寫入資料庫失敗時留下的），同樣超過 7 天才刪。
 *
 * 資料庫在同一個交易中刪除，提交後才刪檔案。dry run 在交易中刪除、算出筆數後還原，不刪檔案。
 */
class Pruner
{
    /**
     * 回報刪除筆數的資料表。被刪的作答、版本會連帶刪掉的資料表（作答明細、題組內容等）不另外列出。
     */
    public const TABLES = [
        'attempts' => '作答紀錄',
        'contributions' => '貢獻紀錄',
        'activities' => '活動',
        'sets' => '題組',
        'items' => '詞條',
        'set_revisions' => '題組版本',
        'media' => '媒體',
    ];

    /**
     * @return array<string, int> TABLES 中每個資料表刪除的筆數，以及 files：刪除的檔案數
     */
    public function run(bool $dryRun = false): array
    {
        $now = CarbonImmutable::now();
        $before = $this->counts();

        DB::beginTransaction();
        try {
            $this->pruneAttempts($now);
            $this->pruneContributions($now);
            $this->pruneTrashed($now);
            $this->pruneRevisions($now);
            $files = $this->pruneMedia($now);
            $after = $this->counts();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $dryRun ? DB::rollBack() : DB::commit();

        $disk = Storage::disk('public');
        if (! $dryRun) {
            $disk->delete($files);
        }
        $orphans = $this->orphanFiles($now);
        if (! $dryRun) {
            $disk->delete($orphans);
        }

        $result = [];
        foreach (self::TABLES as $table => $label) {
            $result[$table] = $before[$table] - $after[$table];
        }
        $result['files'] = count($files) + count($orphans);

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach (array_keys(self::TABLES) as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * 作答明細隨作答紀錄刪除（外鍵 cascade）。
     */
    private function pruneAttempts(CarbonImmutable $now): void
    {
        $cutoff = $now->subMonthsNoOverflow((int) config('kancil.retention.attempt_months'));

        Attempt::where('started_at', '<', $cutoff)->delete();
    }

    /**
     * 貢獻日曆用的每天版本數。date 是台灣時間的日期（Contributions::date()），期限也以台灣時間的日期比較。
     */
    private function pruneContributions(CarbonImmutable $now): void
    {
        $cutoff = Contributions::date($now->subMonthsNoOverflow((int) config('kancil.retention.contribution_months')));

        Contribution::where('date', '<', $cutoff)->delete();
    }

    /**
     * 老師刪除的活動、題組與詞條。網站上沒有復原的功能，保留一段時間是給管理員處理誤刪。
     * 題組的貢獻紀錄隨題組刪除（外鍵 cascade）。
     */
    private function pruneTrashed(CarbonImmutable $now): void
    {
        $cutoff = $now->subDays((int) config('kancil.retention.trashed_days'));

        // 作答紀錄隨活動刪除（外鍵 cascade）
        Activity::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();

        Set::onlyTrashed()->where('deleted_at', '<', $cutoff)->lazyById()->each(function (Set $set) {
            $items = $set->entries()->whereNotNull('item_id')->pluck('item_id');

            // 作答紀錄與審核紀錄指向題組版本，要先刪；題組的內容、版本與冊課隨題組刪除（外鍵 cascade）
            Activity::withTrashed()->where('set_id', $set->id)->forceDelete();
            SetReview::where('set_id', $set->id)->delete();
            $set->forceDelete();

            // 同一位老師的其他題組可能也用到這些詞條
            Item::withTrashed()->whereIn('id', $items)->whereDoesntHave('entries')->forceDelete();
        });

        // 從題組中移除的詞條（App\Corpus\SetWriter）；媒體與冊課的關聯隨詞條刪除（外鍵 cascade）
        Item::onlyTrashed()->where('deleted_at', '<', $cutoff)->whereDoesntHave('entries')->forceDelete();
    }

    private function pruneRevisions(CarbonImmutable $now): void
    {
        $cutoff = $now->subDays((int) config('kancil.retention.revision_days'));

        $ids = SetRevision::query()
            ->whereNotIn('id', Set::withTrashed()->whereNotNull('current_revision_id')->select('current_revision_id'))
            ->whereNotIn('set_id', CurriculumRef::whereNotNull('set_id')->select('set_id'))
            ->whereNotExists(fn (Builder $query) => $query->from('attempts')
                ->whereColumn('attempts.set_revision_id', 'set_revisions.id'))
            ->whereNotExists(fn (Builder $query) => $query->from('set_reviews')
                ->whereColumn('set_reviews.set_revision_id', 'set_revisions.id'))
            ->whereExists(fn (Builder $query) => $query->from('set_revisions as newer')
                ->whereColumn('newer.set_id', 'set_revisions.set_id')
                ->whereColumn('newer.number', '>', 'set_revisions.number')
                ->where('newer.created_at', '<', $cutoff))
            ->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            SetRevision::whereIn('id', $chunk)->delete();
        }
    }

    /**
     * @return list<string> 要刪除的檔案（public disk 上的路徑）
     */
    private function pruneMedia(CarbonImmutable $now): array
    {
        // 詞條的媒體在 item_media，問答題的在 set_entry_media（由 SetEntry 依 payload 同步）
        /** @var Collection<string, Media> $unused */
        $unused = Media::query()
            ->where('created_at', '<', $this->mediaGraceCutoff($now))
            ->whereNotExists(fn (Builder $query) => $query->from('item_media')
                ->whereColumn('item_media.media_id', 'media.id'))
            ->whereNotExists(fn (Builder $query) => $query->from('set_entry_media')
                ->whereColumn('set_entry_media.media_id', 'media.id'))
            ->get(['id', 'path', 'thumbnail_path'])
            ->keyBy('id');

        // 題組版本是內容的快照，引用的媒體只在 JSON 中，沒有對照表，要掃過每一版
        if ($unused->isNotEmpty()) {
            DB::table('set_revisions')->select(['id', 'content'])->lazyById(200)
                ->each(function (object $revision) use ($unused) {
                    $unused->forget(MediaUrls::idsInJson((string) $revision->content));

                    return $unused->isNotEmpty();
                });
        }

        foreach ($unused->keys()->chunk(500) as $chunk) {
            Media::whereIn('id', $chunk)->delete();
        }

        $files = [];
        foreach ($unused as $media) {
            $files[] = $media->path;
            if ($media->thumbnail_path !== null) {
                $files[] = $media->thumbnail_path;
            }
        }

        return $files;
    }

    /**
     * 媒體目錄中沒有對應資料的檔案。檔案先寫入才建立資料（App\Media\MediaProcessor），
     * 所以剛寫入的檔案不算。
     *
     * @return list<string>
     */
    private function orphanFiles(CarbonImmutable $now): array
    {
        $disk = Storage::disk('public');
        $known = Media::pluck('path')
            ->merge(Media::whereNotNull('thumbnail_path')->pluck('thumbnail_path'))
            ->flip();
        $cutoff = $this->mediaGraceCutoff($now)->getTimestamp();

        return array_values(array_filter(
            $disk->files('media'),
            fn (string $file) => ! $known->has($file) && $disk->lastModified($file) < $cutoff,
        ));
    }

    private function mediaGraceCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays((int) config('kancil.retention.media_grace_days'));
    }
}
