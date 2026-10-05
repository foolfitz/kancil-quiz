<?php

namespace App\Corpus;

use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use stdClass;

/**
 * 題組編輯畫面需要的資料：內容的形狀與 SetWriter 的輸入相同，另外附上媒體的網址供預覽，
 * 以及媒體的署名（docs/SPEC.md 第 9 節）。
 */
class SetEditorData
{
    /**
     * @param  User|null  $viewer  正在編輯的人：只有自己上傳的媒體才能修改署名
     * @return array<int, array<string, mixed>>
     */
    public static function entries(Set $set, ?User $viewer = null): array
    {
        $set->load('entries.item.media');
        $media = fn (Media $media) => self::media($media, $viewer);

        if ($set->kind === 'vocab') {
            return $set->entries->map(fn (SetEntry $entry) => [
                'id' => $entry->id,
                'item' => [
                    'text' => $entry->item->text,
                    'romanization' => $entry->item->romanization,
                    'translation_zh' => $entry->item->translation_zh,
                    'audio' => $entry->item->media->where('pivot.role', 'audio')->values()->map($media)->all(),
                    'image' => ($image = $entry->item->media->firstWhere('pivot.role', 'image')) ? $media($image) : null,
                ],
            ])->values()->all();
        }

        $ids = $set->entries->flatMap(fn (SetEntry $entry) => $entry->mediaIds())->unique();
        /** @var Collection<string, Media> $all */
        $all = Media::whereIn('id', $ids)->get()->keyBy('id');
        $find = fn (?string $id) => $id !== null && $all->has($id) ? $media($all->get($id)) : null;

        return $set->entries->map(fn (SetEntry $entry) => [
            'id' => $entry->id,
            'question' => [
                'stem' => [
                    'text' => $entry->payload['stem']['text'] ?? '',
                    'audio' => $find($entry->payload['stem']['audio_id'] ?? null),
                    'image' => $find($entry->payload['stem']['image_id'] ?? null),
                ],
                'options' => array_map(fn (array $option) => [
                    'id' => $option['id'],
                    'text' => $option['text'] ?? '',
                    'image' => $find($option['image_id'] ?? null),
                    'correct' => (bool) $option['correct'],
                ], $entry->payload['options'] ?? []),
            ],
        ])->values()->all();
    }

    /**
     * 媒體的網址與署名。署名的 author 是作者名字以「、」連起來；license、source 為 null 表示沿用詞條與題組（6.5）。
     * editable：是 viewer 自己上傳的，可以在編輯頁修改署名；別人的（例如教材的插圖）只能看。
     *
     * @return array{id: string, kind: string, url: string, thumbnail_url: string|null, duration_ms: int|null, author: string, source: string|null, license: string|null, editable: bool}
     */
    public static function media(Media $media, ?User $viewer = null): array
    {
        return [
            'id' => $media->id,
            'kind' => $media->kind,
            'url' => $media->url(),
            'thumbnail_url' => $media->thumbnailUrl(),
            'duration_ms' => $media->duration_ms,
            'author' => implode('、', array_column($media->authors ?? [], 'name')),
            'source' => $media->source,
            'license' => $media->license,
            'editable' => $viewer !== null && $media->uploaded_by === $viewer->id,
        ];
    }

    /**
     * 題組最新版本的內容（媒體為絕對網址），供老師端做相容檢查與預覽（docs/SPEC.md 7.3）。
     */
    public static function currentContent(Set $set): ?stdClass
    {
        $revision = $set->currentRevision;

        return $revision ? MediaUrls::absolutize($revision->content()) : null;
    }
}
