<?php

namespace App\Corpus;

use App\Curriculum\Textbook;
use App\Models\CurriculumRef;
use App\Models\Set;
use App\Models\SetReview;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * 題組檢視頁（唯讀）的資料：共備庫、同事的分享連結、審核時都用這一頁（docs/SPEC.md T-12、T-13、T-17、C-01）。
 */
class SetViewData
{
    /**
     * @param  string|null  $token  透過分享連結檢視時的 token，複製時要一併送回
     * @return array<string, mixed>
     */
    public static function props(Set $set, User $viewer, ?string $token = null): array
    {
        $set->load(['owner', 'language', 'curriculumRefs', 'currentRevision', 'forkedFrom.owner', 'textbookLesson']);
        $gate = Gate::forUser($viewer);
        $content = SetEditorData::currentContent($set);
        $manage = $gate->allows('manage', $set);
        // 只有待審或已公開的題組需要審核者動作（通過、退回、下架）
        $review = $gate->allows('review', $set) && ($set->review_status === 'pending' || $set->isPublic());
        $source = $set->forkedFrom;

        return [
            'set' => [
                'id' => $set->id,
                'kind' => $set->kind,
                'title' => $set->title,
                'description' => $set->description,
                'language' => ['code' => $set->language_code, 'name_zh' => $set->language->name_zh],
                'license' => $set->license,
                'tags' => $set->tags ?? [],
                'curriculum' => $set->curriculumRefs->map(fn (CurriculumRef $ref) => [
                    'volume' => $ref->volume,
                    'lesson' => $ref->lesson,
                    'title_zh' => $ref->title_zh,
                ])->all(),
                // 教材題組（3.6）：連到那一課的教材頁
                'textbook_url' => $set->textbookLesson ? Textbook::lessonUrl($set->textbookLesson) : null,
                'authors' => array_column($set->effectiveAuthors(), 'name'),
                'owner' => $set->owner->name,
                'visibility' => $set->visibility,
                'review_status' => $set->review_status,
                'revision' => $set->currentRevision?->getAttribute('number'),
                'updated_at' => $set->updated_at?->toIso8601String(),
                'forked_from' => $source === null ? null : [
                    'id' => $source->id,
                    'title' => $source->title,
                    'owner' => $source->owner->name,
                    'viewable' => ! $source->trashed() && $gate->allows('view', $source),
                ],
            ],
            'entries' => $content === null ? [] : array_map(fn (stdClass $entry) => [
                'id' => $entry->id,
                'question' => EntryFaces::question($content, $entry),
                'answer' => EntryFaces::answer($content, $entry),
                'options' => EntryFaces::options($content, $entry),
            ], $content->entries),
            'can' => [
                // 透過分享連結檢視時，持有 token 就能複製
                'copy' => $token !== null || $gate->allows('copy', $set),
                'manage' => $manage,
                'activity' => $gate->allows('createActivity', $set),
                'edit' => $gate->allows('edit', $set),
                'review' => $review,
                // 匯出 zip（T-15）：透過分享連結檢視時，持有 token 就能下載
                'export' => $set->current_revision_id !== null && ($token !== null || $gate->allows('export', $set)),
            ],
            'token' => $token,
            // 修訂紀錄（C-03）：擁有者、能修正或審核的人看得到
            'revisions' => $manage || $review || $gate->allows('edit', $set) ? self::revisions($set) : [],
            'reviews' => $manage || $review ? $set->reviews()->with('user:id,name')->get()->map(fn (SetReview $entry) => [
                'action' => $entry->action,
                'note' => $entry->note,
                'user' => $entry->user->name,
                'created_at' => $entry->created_at->toIso8601String(),
            ])->all() : [],
        ];
    }

    /**
     * 最近的版本與每一版修改了什麼，新的在前。
     *
     * @return list<array{number: int, created_by: string|null, created_at: string|null, changes: list<array<string, string|null>>}>
     */
    private static function revisions(Set $set, int $limit = 30): array
    {
        // 多取一版，才能和最舊的那一版比較
        $revisions = $set->revisions()->with('creator:id,name')->orderByDesc('number')->limit($limit + 1)->get()->values();

        $result = [];
        foreach ($revisions->take($limit) as $i => $revision) {
            $previous = $revisions->get($i + 1);
            $result[] = [
                'number' => $revision->number,
                'created_by' => $revision->creator?->name,
                'created_at' => $revision->created_at?->toIso8601String(),
                // 舊版本沒有作答引用時會被清除（第 5 節），這時無法比較
                'changes' => $previous === null && $revision->number > 1
                    ? [['kind' => 'unknown', 'label' => '更早的版本已清除，無法比較', 'before' => null, 'after' => null]]
                    : RevisionDiff::between($previous?->content(), $revision->content()),
            ];
        }

        return $result;
    }
}
