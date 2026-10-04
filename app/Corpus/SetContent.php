<?php

namespace App\Corpus;

use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use Illuminate\Support\Collection;

/**
 * 從資料庫組出題組的交換格式（docs/SPEC.md 第 6 節），媒體為 zip 內的相對路徑。
 * 題組版本（3.3）的內容就是這個結果。
 */
class SetContent
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @return array<string, mixed>
     */
    public static function build(Set $set): array
    {
        $set->load(['owner', 'textbookLesson', 'curriculumRefs', 'entries.item.media']);

        $content = array_filter([
            'format' => 'kancil-set',
            'version' => 1,
            'id' => $set->id,
            'kind' => $set->kind,
            'language' => $set->language_code,
            'title' => $set->title,
            'description' => $set->description,
            'license' => $set->license,
            'authors' => $set->effectiveAuthors(),
            'curriculum' => $set->curriculumRefs->isEmpty() ? null : $set->curriculumRefs
                ->map(fn (CurriculumRef $ref) => ['volume' => $ref->volume, 'lesson' => $ref->lesson])
                ->all(),
            'tags' => $set->tags ?: null,
        ], fn ($value) => $value !== null);

        if ($set->kind === 'vocab') {
            $content['faces'] = $set->faces;
            $content['entries'] = $set->entries->map(self::vocabEntry(...))->all();
        } else {
            $media = self::quizMedia($set->entries);
            $content['entries'] = $set->entries
                ->map(fn (SetEntry $entry) => self::quizEntry($entry, $media))
                ->all();
        }

        return $content;
    }

    public static function json(Set $set): string
    {
        return json_encode(self::build($set), self::JSON_FLAGS);
    }

    /**
     * @return array<string, mixed>
     */
    private static function vocabEntry(SetEntry $entry): array
    {
        $item = $entry->item;
        $audio = $item->media->where('pivot.role', 'audio')->values();
        $image = $item->media->firstWhere('pivot.role', 'image');

        return [
            'id' => $entry->id,
            'item' => array_filter([
                'text' => $item->text,
                'romanization' => $item->romanization,
                'translation_zh' => $item->translation_zh,
                'audio' => $audio->isEmpty() ? null : $audio->map->toExchange()->all(),
                'image' => $image?->toExchange(),
                'tags' => $item->tags ?: null,
                'authors' => $item->authors ?: null,
                'license' => $item->license,
                'source' => $item->source,
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * 問答組的 payload 以 audio_id、image_id 引用媒體。
     *
     * @param  Collection<int, SetEntry>  $entries
     * @return Collection<string, Media>
     */
    private static function quizMedia(Collection $entries): Collection
    {
        $ids = $entries->flatMap(fn (SetEntry $entry) => $entry->mediaIds())->unique();

        return Media::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  Collection<string, Media>  $media
     * @return array<string, mixed>
     */
    private static function quizEntry(SetEntry $entry, Collection $media): array
    {
        $question = $entry->payload ?? [];
        $find = fn (?string $id) => $id === null ? null : $media->get($id)?->toExchange();

        return [
            'id' => $entry->id,
            'question' => [
                'stem' => array_filter([
                    'text' => $question['stem']['text'] ?? null,
                    'audio' => $find($question['stem']['audio_id'] ?? null),
                    'image' => $find($question['stem']['image_id'] ?? null),
                ], fn ($value) => $value !== null),
                'options' => array_map(fn (array $option) => array_filter([
                    'id' => $option['id'],
                    'text' => $option['text'] ?? null,
                    'image' => $find($option['image_id'] ?? null),
                    'correct' => (bool) $option['correct'],
                ], fn ($value) => $value !== null), $question['options'] ?? []),
            ],
        ];
    }
}
