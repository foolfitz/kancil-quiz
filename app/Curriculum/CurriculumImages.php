<?php

namespace App\Curriculum;

use App\Corpus\SetWriter;
use App\Media\MediaProcessor;
use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Normalizer;
use Transliterator;

/**
 * 在後台批次上傳一冊教材的插圖，依檔名對應到詞（docs/SPEC.md 3.6、A-03）。
 *
 * - 檔名去掉副檔名後，與詞用同一套規則比對：轉小寫，空白與符號都視為「_」。
 *   例：ibu_guru.png 對應 ibu guru，tidak_apa_apa.png 對應 tidak apa-apa。
 * - 找不到完全相同的，才忽略聲調等附加符號比對（越南語的檔名常不打聲調），只在剛好一個詞符合時採用。
 * - 範圍是一冊：同一冊中好幾課都有的詞，用同一張圖。
 * - 已經有圖的詞預設不動，$replace 時換掉。
 * - 以教材帳號產生新版本，不算審核者的修正（CurriculumImporter::editedOnSite()）。
 *
 * @phpstan-import-type Attribution from VolumeFile
 *
 * @phpstan-type Word array{key: string, text: string, translation_zh: string, lessons: list<int>, missing: bool, entries: list<SetEntry>}
 */
final class CurriculumImages
{
    public function __construct(private SetWriter $writer, private MediaProcessor $media) {}

    /**
     * 這一冊教材題組中的詞，以檔名的對應鍵分組，依課次排列。
     *
     * @return array<string, Word>
     */
    public static function words(string $language, int $volume): array
    {
        $words = [];
        $refs = CurriculumRef::query()
            ->where(['language_code' => $language, 'volume' => $volume])
            ->whereNotNull('set_id')
            ->with('set.entries.item.media')
            ->orderBy('lesson')
            ->get();

        foreach ($refs as $ref) {
            foreach ($ref->set->entries ?? [] as $entry) {
                if ($entry->item === null) {
                    continue;
                }
                $key = self::fileKey($entry->item->text);
                $words[$key] ??= ['key' => $key, 'text' => $entry->item->text, 'translation_zh' => $entry->item->translation_zh, 'lessons' => [], 'missing' => false, 'entries' => []];
                $words[$key]['lessons'] = array_values(array_unique([...$words[$key]['lessons'], $ref->lesson]));
                $words[$key]['missing'] = $words[$key]['missing'] || self::image($entry) === null;
                $words[$key]['entries'][] = $entry;
            }
        }

        return $words;
    }

    /**
     * 例：selamat pagi（早安），第 1、4 課
     *
     * @param  Word  $word
     */
    public static function label(array $word): string
    {
        return "{$word['text']}（{$word['translation_zh']}），第 ".implode('、', $word['lessons']).' 課';
    }

    /**
     * 檔名可能對應的詞：完全相同的只會有一個；忽略附加符號比對時可能有好幾個，要由管理員選。
     *
     * @param  array<string, Word>  $words
     * @return list<string> words() 的鍵
     */
    public static function candidates(string $filename, array $words): array
    {
        $key = self::fileKey(pathinfo($filename, PATHINFO_FILENAME));
        if (isset($words[$key])) {
            return [$key];
        }

        $loose = self::looseKey($key);

        return array_values(array_filter(array_keys($words), fn (string $word) => self::looseKey($word) === $loose));
    }

    /**
     * 轉小寫，字母、數字以外的字元都視為「_」。例：tidak apa-apa → tidak_apa_apa
     */
    public static function fileKey(string $text): string
    {
        $text = mb_strtolower((string) Normalizer::normalize($text, Normalizer::FORM_C));

        return trim((string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '_', $text), '_');
    }

    /**
     * 再去掉聲調等附加符號。例：chào_buổi_sáng → chao_buoi_sang
     */
    private static function looseKey(string $key): string
    {
        static $transliterator;
        $transliterator ??= Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII');

        return $transliterator instanceof Transliterator ? (string) $transliterator->transliterate($key) : $key;
    }

    /**
     * 把圖加到詞上。每個檔案只轉檔一次，同一冊中好幾課都有的詞共用。
     *
     * @param  array<string, string>  $files  words() 的鍵 => 圖檔路徑
     * @param  Attribution  $attribution
     * @return array{words: int, lessons: list<int>}
     *
     * @throws ValidationException 有詞已經不在這一冊，或圖檔無法讀取
     */
    public function attach(string $language, int $volume, array $files, array $attribution, bool $replace = false): array
    {
        $words = self::words($language, $volume);
        $owner = Textbook::owner();

        $targets = [];
        foreach ($files as $key => $path) {
            $word = $words[$key] ?? throw ValidationException::withMessages(['files' => "第 {$volume} 冊沒有「{$key}」這個詞，請重新配對。"]);
            $entries = array_filter($word['entries'], fn (SetEntry $entry) => $replace || self::image($entry) === null);
            if ($entries !== []) {
                $targets[] = [$path, $entries];
            }
        }

        // 先把圖都轉好，有無法讀取的圖就整批不寫入
        $images = [];
        foreach ($targets as [$path, $entries]) {
            $media = $this->media->imageFromPath($path, $owner, $attribution);
            foreach ($entries as $entry) {
                $images[$entry->id] = $media->id;
            }
        }

        $sets = collect($targets)->flatMap(fn (array $target) => $target[1])->map(fn (SetEntry $entry) => $entry->set_id)->unique();
        $lessons = [];
        DB::transaction(function () use ($sets, $images, $owner, &$lessons) {
            foreach (Set::with(['entries.item.media', 'textbookLesson'])->findMany($sets) as $set) {
                $entries = $set->entries->filter(fn (SetEntry $entry) => $entry->item !== null)->map(fn (SetEntry $entry) => [
                    'id' => $entry->id,
                    'item' => [
                        'text' => $entry->item->text,
                        'romanization' => $entry->item->romanization,
                        'translation_zh' => $entry->item->translation_zh,
                        'audio_ids' => $entry->item->media->where('pivot.role', 'audio')->pluck('id')->all(),
                        'image_id' => $images[$entry->id] ?? self::image($entry)?->id,
                    ],
                ])->values()->all();
                $this->writer->write($set, ['faces' => $set->faces ?? Textbook::FACES, 'entries' => $entries], $owner);
                if ($set->textbookLesson !== null) {
                    $lessons[] = $set->textbookLesson->lesson;
                }
            }
        });
        sort($lessons);

        return ['words' => count($targets), 'lessons' => $lessons];
    }

    /**
     * 一課的教材題組中還沒有插圖的詞（後台的教材對照表）。題組要先載入 entries.item.media。
     *
     * @return list<string>
     */
    public static function missing(Set $set): array
    {
        return array_values($set->entries
            ->filter(fn (SetEntry $entry) => $entry->item !== null && self::image($entry) === null)
            ->map(fn (SetEntry $entry) => (string) $entry->item?->text)
            ->all());
    }

    private static function image(SetEntry $entry): ?Media
    {
        $image = $entry->item?->media->firstWhere('pivot.role', 'image');

        return $image instanceof Media ? $image : null;
    }
}
