<?php

namespace App\Support;

/**
 * 授權（SPDX 識別碼）的顯示名稱與條款網址：題組、詞條與媒體的署名（docs/SPEC.md 第 9 節、D-3、D-4）。
 */
final class Licenses
{
    private const KNOWN = [
        'CC-BY-4.0' => ['CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/'],
        'CC-BY-SA-4.0' => ['CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/'],
        // 國教署《新住民語文學習教材》的授權，教材的課名與詞彙照原教材標示（D-4）
        'CC-BY-NC-ND-4.0' => ['CC BY-NC-ND 4.0', 'https://creativecommons.org/licenses/by-nc-nd/4.0/'],
        'CC0-1.0' => ['CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
    ];

    /**
     * 例：CC BY 4.0。不認得的識別碼原樣傳回。
     */
    public static function name(string $license): string
    {
        return self::KNOWN[$license][0] ?? $license;
    }

    public static function url(string $license): ?string
    {
        return self::KNOWN[$license][1] ?? null;
    }
}
