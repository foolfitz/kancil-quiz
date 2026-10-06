<?php

namespace App\Corpus;

use App\Models\Item;
use App\Models\Set;
use App\Models\SetRevision;
use App\Models\User;
use App\Profile\Contributions;
use App\Support\KancilFormat;
use Illuminate\Support\Facades\DB;

/**
 * 題組內容變動後產生新的不可變版本（docs/SPEC.md 3.3）。內容與上一版相同時不產生新版本。
 */
class SetRevisionRecorder
{
    public function __construct(private KancilFormat $format) {}

    public function record(Set $set, ?User $by = null): SetRevision
    {
        $json = SetContent::json($set);
        $hash = hash('sha256', $json);

        $current = $set->currentRevision()->first();
        if ($current !== null && $current->content_hash === $hash) {
            return $current;
        }

        $errors = $this->format->setErrors(json_decode($json, flags: JSON_THROW_ON_ERROR));
        if ($errors !== []) {
            throw new InvalidSetContent($errors);
        }

        return DB::transaction(function () use ($set, $json, $hash, $by) {
            $revision = $set->revisions()->create([
                'number' => (int) $set->revisions()->max('number') + 1,
                'content' => $json,
                'content_hash' => $hash,
                'created_by' => $by?->id,
            ]);

            $set->forceFill(['current_revision_id' => $revision->id])->save();

            // 創作者頁面的貢獻日曆（docs/SPEC.md T-20）：版本可能被清除，每天的次數另外累計
            if ($by !== null) {
                Contributions::record($set, $by, $revision->created_at);
            }

            return $revision;
        });
    }

    /**
     * 詞條被修改後，所有引用它的題組都要產生新版本。
     */
    public function recordForItem(Item $item, ?User $by = null): void
    {
        Set::whereHas('entries', fn ($query) => $query->where('item_id', $item->id))
            ->get()
            ->each(fn (Set $set) => $this->record($set, $by));
    }
}
