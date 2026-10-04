<?php

namespace App\Corpus;

use App\Models\Media;
use stdClass;

/**
 * 交換格式中的媒體路徑是 zip 內的相對路徑 media/<ID>.<副檔名>；
 * API 輸出時改寫成可以直接載入的絕對網址（docs/SPEC.md 6.5）。
 */
class MediaUrls
{
    private const PATTERN = '#^media/([0-9A-HJKMNP-TV-Z]{26})\.[a-z0-9]+$#';

    public static function absolutize(stdClass $content): stdClass
    {
        $ids = [];
        self::walk($content, function (stdClass $media) use (&$ids) {
            if (preg_match(self::PATTERN, $media->src, $match)) {
                $ids[] = $match[1];
            }
        });

        $urls = Media::whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (Media $media) => [$media->id => $media->url()]);

        $result = unserialize(serialize($content));
        assert($result instanceof stdClass);

        self::walk($result, function (stdClass $media) use ($urls) {
            if (preg_match(self::PATTERN, $media->src, $match) && $urls->has($match[1])) {
                $media->src = $urls->get($match[1]);
            }
        });

        return $result;
    }

    /**
     * 內容引用的媒體：zip 內的相對路徑對應到媒體 ID（匯出 zip 時用，docs/SPEC.md 6.2）。
     *
     * @return array<string, string>
     */
    public static function referenced(stdClass $content): array
    {
        $paths = [];
        self::walk($content, function (stdClass $media) use (&$paths) {
            if (preg_match(self::PATTERN, $media->src, $match)) {
                $paths[$media->src] = $match[1];
            }
        });

        return $paths;
    }

    /**
     * 對內容中每個媒體物件（有 src 字串的物件）呼叫 $callback。
     *
     * @param  callable(stdClass): void  $callback
     */
    private static function walk(mixed $value, callable $callback): void
    {
        if ($value instanceof stdClass) {
            if (isset($value->src) && is_string($value->src)) {
                $callback($value);
            }
            foreach (get_object_vars($value) as $child) {
                self::walk($child, $callback);
            }
        } elseif (is_array($value)) {
            foreach ($value as $child) {
                self::walk($child, $callback);
            }
        }
    }
}
