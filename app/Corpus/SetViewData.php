<?php

namespace App\Corpus;

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
        $set->load(['owner', 'language', 'curriculumRefs', 'currentRevision', 'forkedFrom.owner']);
        $gate = Gate::forUser($viewer);
        $content = SetEditorData::currentContent($set);
        $manage = $gate->allows('manage', $set);
        $review = $gate->allows('review', $set);
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
                'manage' => $manage,
                'edit' => $gate->allows('edit', $set),
                'review' => $review,
            ],
            'token' => $token,
            'reviews' => $manage || $review ? $set->reviews()->with('user:id,name')->get()->map(fn (SetReview $entry) => [
                'action' => $entry->action,
                'note' => $entry->note,
                'user' => $entry->user->name,
                'created_at' => $entry->created_at->toIso8601String(),
            ])->all() : [],
        ];
    }
}
