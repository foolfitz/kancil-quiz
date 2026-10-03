<?php

namespace App\Grading;

use stdClass;

/**
 * 成績判定，規則見 docs/SPEC.md 7.4。與 packages/deck/src/judge.ts 是同一套規則，
 * 兩邊共用 packages/schema/fixtures/grading/ 的案例測試。
 */
class Judge
{
    /**
     * @param  stdClass  $set  作答時的題組版本內容（交換格式）
     * @param  list<string>  $presented  這一題出現了哪些選項
     * @param  list<string>  $selected
     * @return bool|null null 表示這種形狀不計分（例如字卡）
     */
    public static function judge(stdClass $set, string $shape, string $entryId, array $presented, array $selected): ?bool
    {
        if ($shape === 'card') {
            return null;
        }

        if (count($selected) !== 1 || ! in_array($selected[0], $presented, true)) {
            return false;
        }

        $choice = $selected[0];
        $entry = null;
        foreach ($set->entries as $candidate) {
            if ($candidate->id === $entryId) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry === null) {
            return false;
        }

        if ($set->kind === 'quiz' && $shape === 'mcq') {
            foreach ($entry->question->options as $option) {
                if ($option->id === $choice) {
                    return $option->correct === true;
                }
            }

            return false;
        }

        // 詞彙組的選項 id 就是詞條的 entry id；配對時右側卡片也以 entry id 識別。
        return $choice === $entryId;
    }

    /**
     * 每題以第一筆作答計算（例如迷宮答錯後重試，仍算答錯）。
     *
     * @param  iterable<array{entry_id: string, presented: list<string>, selected: list<string>|null}>  $responses  依作答順序
     */
    public static function countCorrect(stdClass $set, string $shape, iterable $responses): ?int
    {
        if ($shape === 'card') {
            return null;
        }

        $first = [];
        foreach ($responses as $response) {
            $first[$response['entry_id']] ??= $response;
        }

        return count(array_filter(
            $first,
            fn (array $response) => self::judge($set, $shape, $response['entry_id'], $response['presented'], $response['selected'] ?? []) === true,
        ));
    }
}
