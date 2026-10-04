<?php

namespace App\Grading;

use Normalizer;

/**
 * 學生輸入的名字或座號（docs/SPEC.md 3.4、S-04）。
 *
 * 存入前統一格式，讓同一位學生在不同裝置、不同輸入法下打的字對得上：
 * 全形英數字轉半形（NFKC）、合併連續空白、去掉頭尾空白；只有數字時去掉前導的 0（「05」即「5」）。
 */
final class PlayerLabel
{
    public const MAX_LENGTH = 20;

    public static function normalize(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = Normalizer::normalize($label, Normalizer::FORM_KC) ?: $label;
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        if (preg_match('/^[0-9]+$/', $label) === 1) {
            $label = ltrim($label, '0') ?: '0';
        }

        return $label === '' ? null : $label;
    }

    /**
     * 結果頁依學生分組的鍵：不分大小寫（「Andi」與「andi」是同一位）。
     */
    public static function key(string $label): string
    {
        return mb_strtolower($label);
    }
}
