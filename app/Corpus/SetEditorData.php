<?php

namespace App\Corpus;

use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use Illuminate\Support\Collection;
use stdClass;

/**
 * 題組編輯畫面需要的資料：內容的形狀與 SetWriter 的輸入相同，另外附上媒體的網址供預覽。
 */
class SetEditorData
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function entries(Set $set): array
    {
        $set->load('entries.item.media');

        if ($set->kind === 'vocab') {
            return $set->entries->map(fn (SetEntry $entry) => [
                'id' => $entry->id,
                'item' => [
                    'text' => $entry->item->text,
                    'romanization' => $entry->item->romanization,
                    'translation_zh' => $entry->item->translation_zh,
                    'audio' => $entry->item->media->where('pivot.role', 'audio')->values()->map(self::media(...))->all(),
                    'image' => ($image = $entry->item->media->firstWhere('pivot.role', 'image')) ? self::media($image) : null,
                ],
            ])->values()->all();
        }

        $ids = $set->entries->flatMap(fn (SetEntry $entry) => $entry->mediaIds())->unique();
        /** @var Collection<string, Media> $media */
        $media = Media::whereIn('id', $ids)->get()->keyBy('id');
        $find = fn (?string $id) => $id !== null && $media->has($id) ? self::media($media->get($id)) : null;

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
     * @return array{id: string, kind: string, url: string, thumbnail_url: string|null, duration_ms: int|null}
     */
    public static function media(Media $media): array
    {
        return [
            'id' => $media->id,
            'kind' => $media->kind,
            'url' => $media->url(),
            'thumbnail_url' => $media->thumbnailUrl(),
            'duration_ms' => $media->duration_ms,
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
