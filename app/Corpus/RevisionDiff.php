<?php

namespace App\Corpus;

use stdClass;

/**
 * 比較兩個題組版本，列出老師看得懂的修改摘要，作為修訂紀錄（docs/SPEC.md C-03、3.3）。
 *
 * @phpstan-type Change array{kind: 'created'|'field'|'added'|'removed'|'changed'|'reordered', label: string, before: string|null, after: string|null}
 */
class RevisionDiff
{
    private const FIELDS = [
        'title' => '標題',
        'description' => '說明',
        'license' => '授權',
        'faces' => '出題方式',
        'curriculum' => '對應教材',
        'tags' => '標籤',
    ];

    /**
     * @param  stdClass|null  $old  上一個版本；null 表示這是第一版
     * @return list<Change>
     */
    public static function between(?stdClass $old, stdClass $new): array
    {
        if ($old === null) {
            return [self::change('created', '建立題組')];
        }

        $changes = [];
        foreach (self::FIELDS as $field => $label) {
            $before = self::fieldText($field, $old->{$field} ?? null);
            $after = self::fieldText($field, $new->{$field} ?? null);
            if ($before !== $after) {
                $changes[] = self::change('field', $label, $before, $after);
            }
        }

        $oldEntries = self::byId($old->entries);
        $newEntries = self::byId($new->entries);

        foreach ($newEntries as $id => $entry) {
            if (! isset($oldEntries[$id])) {
                $changes[] = self::change('added', '新增', null, self::entryText($new, $entry));
            } elseif (json_encode($oldEntries[$id]) !== json_encode($entry)) {
                $changes[] = self::change('changed', '修改', self::entryText($old, $oldEntries[$id]), self::entryText($new, $entry));
            }
        }
        foreach ($oldEntries as $id => $entry) {
            if (! isset($newEntries[$id])) {
                $changes[] = self::change('removed', '刪除', self::entryText($old, $entry), null);
            }
        }

        $kept = array_values(array_intersect(array_keys($oldEntries), array_keys($newEntries)));
        $keptInNewOrder = array_values(array_intersect(array_keys($newEntries), array_keys($oldEntries)));
        if ($kept !== $keptInNewOrder) {
            $changes[] = self::change('reordered', '調整題目順序');
        }

        return $changes;
    }

    /**
     * @param  'created'|'field'|'added'|'removed'|'changed'|'reordered'  $kind
     * @return Change
     */
    private static function change(string $kind, string $label, ?string $before = null, ?string $after = null): array
    {
        return ['kind' => $kind, 'label' => $label, 'before' => $before, 'after' => $after];
    }

    /**
     * @param  list<stdClass>  $entries
     * @return array<string, stdClass>
     */
    private static function byId(array $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $result[$entry->id] = $entry;
        }

        return $result;
    }

    private const FACE_FIELDS = [
        'text' => '目標語',
        'romanization' => '羅馬拼寫',
        'translation_zh' => '中文',
        'audio' => '發音',
        'image' => '圖片',
    ];

    private static function fieldText(string $field, mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        if ($field === 'curriculum' && is_array($value)) {
            return implode('、', array_map(fn (stdClass $ref) => "第 {$ref->volume} 冊第 {$ref->lesson} 課", $value));
        }

        if ($field === 'faces' && $value instanceof stdClass) {
            $names = fn (array $fields) => implode('＋', array_map(fn (string $name) => self::FACE_FIELDS[$name] ?? $name, $fields));

            return "看{$names($value->prompt)}，選{$names($value->answer)}";
        }

        if (is_array($value)) {
            return implode('、', array_map('strval', $value));
        }

        return is_scalar($value) ? (string) $value : json_encode($value, SetContent::JSON_FLAGS);
    }

    /**
     * 一題的文字摘要，包含題目與答案，媒體以「（圖片）」「（音檔）」表示，更換媒體也看得出來。
     */
    private static function entryText(stdClass $set, stdClass $entry): string
    {
        $parts = [self::faceText(EntryFaces::question($set, $entry))];

        foreach (EntryFaces::options($set, $entry) as $option) {
            $parts[] = ($option['correct'] ? '✓' : '').self::faceText($option['face']);
        }

        return implode('｜', $parts);
    }

    /**
     * @param  array{text: string|null, note: string|null, image: string|null, audio: string|null}  $face
     */
    private static function faceText(array $face): string
    {
        $text = trim(($face['text'] ?? '').($face['note'] !== null ? "（{$face['note']}）" : ''));
        // 媒體以檔名（媒體 ID）末四碼區分，換了檔案也看得出來
        $short = fn (string $src) => substr(pathinfo($src, PATHINFO_FILENAME), -4);
        $media = array_filter([
            $face['image'] !== null ? '圖片 #'.$short($face['image']) : null,
            $face['audio'] !== null ? '音檔 #'.$short($face['audio']) : null,
        ]);

        return trim($text.($media === [] ? '' : ' ['.implode('、', $media).']')) ?: '（空白）';
    }
}
