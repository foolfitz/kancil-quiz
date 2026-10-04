<?php

namespace App\Curriculum;

use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\SetEntry;

/**
 * 老師端頁面用的教材資料：某種語言的每一冊、每一課，以及教材題組中的詞（docs/SPEC.md 3.6、T-18）。
 */
class TextbookData
{
    /**
     * 依冊、課排列；還沒有匯入詞彙的課 words 為空。
     *
     * @return list<array{volume: int, lesson: int, title_zh: string|null, title_native: string|null, url: string, words: list<array{id: string, text: string, translation_zh: string, thumbnail_url: string|null}>}>
     */
    public static function lessons(string $language): array
    {
        return array_values(CurriculumRef::query()
            ->where('language_code', $language)
            ->with('set.entries.item.media')
            ->orderBy('volume')
            ->orderBy('lesson')
            ->get()
            ->map(fn (CurriculumRef $ref) => [
                'volume' => $ref->volume,
                'lesson' => $ref->lesson,
                'title_zh' => $ref->title_zh,
                'title_native' => $ref->title_native,
                'url' => Textbook::lessonUrl($ref),
                'words' => $ref->set === null ? [] : array_values($ref->set->entries
                    ->filter(fn (SetEntry $entry) => $entry->item !== null)
                    ->map(fn (SetEntry $entry) => [
                        'id' => $entry->id,
                        'text' => $entry->item->text,
                        'translation_zh' => $entry->item->translation_zh,
                        'thumbnail_url' => self::thumbnail($entry),
                    ])->all()),
            ])->all());
    }

    private static function thumbnail(SetEntry $entry): ?string
    {
        $image = $entry->item->media->firstWhere('pivot.role', 'image');

        return $image instanceof Media ? ($image->thumbnailUrl() ?? $image->url()) : null;
    }
}
