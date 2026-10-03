<?php

namespace App\Corpus;

use stdClass;

/**
 * 在老師端畫面上顯示題組內容（交換格式）中的題目與答案：成績頁（T-11）、題組檢視頁與共備庫。
 *
 * @phpstan-type Face array{text: string|null, note: string|null, image: string|null, audio: string|null}
 */
class EntryFaces
{
    /**
     * 題目：詞彙組顯示詞條本身（目標語與中文），問答組顯示題幹。
     *
     * @return Face
     */
    public static function question(stdClass $set, stdClass $entry): array
    {
        if ($set->kind === 'vocab') {
            return self::itemFace($entry->item);
        }

        $stem = $entry->question->stem;

        return [
            'text' => $stem->text ?? null,
            'note' => null,
            'image' => $stem->image->src ?? null,
            'audio' => $stem->audio->src ?? null,
        ];
    }

    /**
     * 正解：問答組是標示為正解的選項；詞彙組的正解就是題目本身，回傳 null。
     *
     * @return Face|null
     */
    public static function answer(stdClass $set, stdClass $entry): ?array
    {
        if ($set->kind === 'vocab') {
            return null;
        }

        foreach ($entry->question->options as $option) {
            if ($option->correct === true) {
                return self::optionFace($option);
            }
        }

        return null;
    }

    /**
     * 學生選的答案。問答組的選項 ID 在整個版本中唯一；詞彙組的選項 ID 就是詞條的 entry ID（7.4）。
     *
     * @return Face|null 找不到這個選項時為 null
     */
    public static function choice(stdClass $set, string $choice): ?array
    {
        foreach ($set->entries as $entry) {
            if ($set->kind === 'vocab') {
                if ($entry->id === $choice) {
                    return self::itemFace($entry->item);
                }

                continue;
            }

            foreach ($entry->question->options as $option) {
                if ($option->id === $choice) {
                    return self::optionFace($option);
                }
            }
        }

        return null;
    }

    /**
     * @return Face
     */
    private static function itemFace(stdClass $item): array
    {
        return [
            'text' => trim($item->text.' '.($item->romanization ?? '')),
            'note' => $item->translation_zh ?? null,
            'image' => $item->image->src ?? null,
            'audio' => $item->audio[0]->src ?? null,
        ];
    }

    /**
     * @return Face
     */
    private static function optionFace(stdClass $option): array
    {
        return [
            'text' => $option->text ?? null,
            'note' => null,
            'image' => $option->image->src ?? null,
            'audio' => null,
        ];
    }

    /**
     * 問答組的所有選項，供題組檢視頁預覽。詞彙組回傳空陣列。
     *
     * @return list<array{face: Face, correct: bool}>
     */
    public static function options(stdClass $set, stdClass $entry): array
    {
        if ($set->kind === 'vocab') {
            return [];
        }

        return array_values(array_map(fn (stdClass $option) => [
            'face' => self::optionFace($option),
            'correct' => $option->correct === true,
        ], $entry->question->options));
    }
}
