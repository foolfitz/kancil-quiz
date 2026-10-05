<?php

namespace App\Corpus;

use App\Models\Media;
use App\Models\SetRevision;
use App\Support\Licenses;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use stdClass;
use ZipArchive;

/**
 * 題組匯出成交換格式的 zip（docs/SPEC.md T-15、6.2）。set.json 就是題組最新版本的內容，
 * 媒體以媒體 ID 命名放在 media/，LICENSE.txt 列出題組的授權，以及署名與外層不同的詞條與媒體。
 *
 * @phpstan-type Credit array{authors: list<string>, license: string, source: string|null}
 */
class SetExport
{
    /**
     * 建立 zip 暫存檔，回傳路徑；呼叫端用完要刪除。
     */
    public static function zip(SetRevision $revision): string
    {
        $content = $revision->content();
        $paths = MediaUrls::referenced($content);
        $media = Media::whereIn('id', array_unique(array_values($paths)))->get()->keyBy('id');

        $path = (string) tempnam(sys_get_temp_dir(), 'kancil-set-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('無法建立 zip 檔');
        }

        $zip->addFromString('set.json', json_encode($content, SetContent::JSON_FLAGS | JSON_PRETTY_PRINT)."\n");
        foreach ($paths as $zipPath => $id) {
            $file = $media->has($id) ? Storage::disk('public')->path($media->get($id)->path) : null;
            if ($file === null || ! is_file($file)) {
                throw new RuntimeException("找不到媒體檔：{$id}");
            }
            $zip->addFile($file, $zipPath);
        }
        $zip->addFromString('LICENSE.txt', self::license($content, (int) $revision->getAttribute('number')));
        $zip->close();

        return $path;
    }

    /**
     * 題組的授權，以及作者、授權或出處與外層不同的詞條與媒體（省略的欄位沿用外層，6.5）。
     */
    public static function license(stdClass $set, int $revision): string
    {
        $base = [
            'authors' => self::names($set->authors ?? []),
            'license' => (string) $set->license,
            'source' => null,
        ];

        $credits = [];
        foreach ($set->entries as $i => $entry) {
            $number = $i + 1;
            if ($set->kind === 'vocab') {
                $item = $entry->item;
                $label = "第 {$number} 題 {$item->text}（{$item->translation_zh}）";
                $itemCredit = self::inherit($item, $base);
                if ($itemCredit !== $base) {
                    $credits[] = "{$label}：".self::describe($itemCredit, $base);
                }
                foreach ($item->audio ?? [] as $audio) {
                    $credits[] = self::mediaCredit("{$label}的發音", $audio, $itemCredit);
                }
                $credits[] = self::mediaCredit("{$label}的圖片", $item->image ?? null, $itemCredit);

                continue;
            }

            $stem = $entry->question->stem;
            $label = "第 {$number} 題".(isset($stem->text) ? "「{$stem->text}」" : '');
            $credits[] = self::mediaCredit("{$label}題幹的音檔", $stem->audio ?? null, $base);
            $credits[] = self::mediaCredit("{$label}題幹的圖片", $stem->image ?? null, $base);
            foreach ($entry->question->options as $option) {
                $name = isset($option->text) ? "選項「{$option->text}」" : "選項 {$option->id}";
                $credits[] = self::mediaCredit("{$label}{$name}的圖片", $option->image ?? null, $base);
            }
        }
        $credits = array_values(array_filter($credits));

        $lines = [
            $set->title,
            "Kancil Quiz 題組 {$set->id}，第 {$revision} 版",
            '',
            '授權：'.self::licenseName($base['license']),
            '作者：'.implode('、', $base['authors']),
        ];
        if ($credits !== []) {
            array_push($lines, '', '以下詞條與媒體的作者、授權或出處與題組不同：', '', ...array_map(fn (string $line) => "- {$line}", $credits));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  Credit  $parent
     */
    private static function mediaCredit(string $label, ?stdClass $media, array $parent): ?string
    {
        if ($media === null) {
            return null;
        }
        $credit = self::inherit($media, $parent);

        return $credit === $parent ? null : "{$label}（{$media->src}）：".self::describe($credit, $parent);
    }

    /**
     * @param  Credit  $parent
     * @return Credit
     */
    private static function inherit(stdClass $node, array $parent): array
    {
        return [
            'authors' => isset($node->authors) ? self::names($node->authors) : $parent['authors'],
            'license' => $node->license ?? $parent['license'],
            'source' => $node->source ?? $parent['source'],
        ];
    }

    /**
     * 只列出與外層不同的欄位。
     *
     * @param  Credit  $credit
     * @param  Credit  $parent
     */
    private static function describe(array $credit, array $parent): string
    {
        return implode('；', array_filter([
            $credit['authors'] === $parent['authors'] ? null : '作者 '.implode('、', $credit['authors']),
            $credit['license'] === $parent['license'] ? null : '授權 '.self::licenseName($credit['license']),
            $credit['source'] === null || $credit['source'] === $parent['source'] ? null : "出處 {$credit['source']}",
        ]));
    }

    /**
     * @param  array<stdClass>  $authors
     * @return list<string>
     */
    private static function names(array $authors): array
    {
        return array_values(array_map(fn (stdClass $author) => (string) $author->name, $authors));
    }

    private static function licenseName(string $license): string
    {
        $url = Licenses::url($license);

        return Licenses::name($license).($url === null ? '' : "（{$url}）");
    }
}
