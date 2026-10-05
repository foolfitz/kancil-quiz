<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 公開頁面的標題、描述與連結預覽（docs/SPEC.md 10.3、S-06）。
 *
 * 同一份資料交給 Vue 的 <Head>（resources/js/components/kancil/PageMeta.vue），也由
 * resources/views/partials/page-meta.blade.php 寫進伺服器輸出的 <head>：搜尋引擎與 LINE 等的連結預覽不必執行 JS 就讀得到。
 */
final class PageMeta
{
    /**
     * @return array{title: string, description: string, url: string, image: string|null}
     */
    public static function make(string $title, string $description, string $url, ?string $image = null): array
    {
        return [
            'title' => $title,
            'description' => Str::limit((string) preg_replace('/\s+/u', ' ', trim($description)), 160),
            'url' => $url,
            'image' => $image,
        ];
    }
}
