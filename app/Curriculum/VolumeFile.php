<?php

namespace App\Curriculum;

use App\Models\Language;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Normalizer;

/**
 * 讀取一冊教材的資料檔，轉成 CurriculumImporter 使用的格式（docs/SPEC.md 3.6）。
 *
 * 兩種格式：
 * - volume.json：repo 中 database/curriculum/<語言>/<冊>/ 的格式，見該目錄的 README。
 * - 整理教材時的「課文與詞彙.json」：book_title、lessons[].title.{<目標語>, chinese}、
 *   lessons[].vocabulary[].{<目標語>, chinese, page}。目標語的欄位名稱不限（例如 indonesian），
 *   是 chinese、page 以外唯一的欄位。課文（text）不讀（D-4）。
 *
 * 在後台上傳的資料檔不含插圖：插圖另外上傳，依檔名對應到詞（CurriculumImages）。
 *
 * @phpstan-type Word array{text: string, translation_zh: string, page: int|null, image: string|null}
 * @phpstan-type Lesson array{lesson: int, title_zh: string, title_native: string|null, vocabulary: list<Word>}
 * @phpstan-type Attribution array{authors: list<array{name: string}>, license: string, source: string}
 * @phpstan-type Volume array{language: string, volume: int, textbook: string, authors: list<array{name: string}>, license: string, images: Attribution|null, lessons: list<Lesson>}
 */
final class VolumeFile
{
    /**
     * repo 中一冊教材的目錄：volume.json 加插圖。
     *
     * @return Volume
     *
     * @throws ValidationException
     */
    public static function fromDirectory(string $directory): array
    {
        $file = "{$directory}/volume.json";
        if (! is_file($file)) {
            throw ValidationException::withMessages(['volume' => "找不到 {$file}"]);
        }

        return self::validate(self::decode((string) file_get_contents($file)), $directory);
    }

    /**
     * 在後台上傳的資料檔。語言與冊由管理員選擇，資料檔中寫的語言或冊不同時擋下，以免匯入到別冊。
     *
     * @return Volume
     *
     * @throws ValidationException
     */
    public static function fromUpload(string $json, string $language, int $volume): array
    {
        $data = self::decode($json);

        if (self::isVolumeJson($data)) {
            if (($data['language'] ?? $language) !== $language || (int) ($data['volume'] ?? $volume) !== $volume) {
                $written = ($data['language'] ?? $language).' 第 '.($data['volume'] ?? $volume).' 冊';
                throw ValidationException::withMessages(['file' => "資料檔寫的是 {$written}，與選擇的不同。"]);
            }
        } else {
            self::checkBookTitle($data['book_title'] ?? null, $language, $volume);
            $data = self::convert($data);
        }

        $data['language'] = $language;
        $data['volume'] = $volume;
        $data['textbook'] ??= self::defaultTextbook($language, $volume);
        $data['authors'] ??= Textbook::AUTHORS;
        $data['license'] ??= Textbook::LICENSE;
        // 插圖另外上傳；資料檔中的插圖路徑不用
        foreach ($data['lessons'] ?? [] as $i => $lesson) {
            foreach ($lesson['vocabulary'] ?? [] as $j => $word) {
                if (is_array($word)) {
                    unset($data['lessons'][$i]['vocabulary'][$j]['image']);
                }
            }
        }

        return self::validate($data, null);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['file' => '無法讀取資料檔：不是有效的 JSON。']);
        }
        if (! is_array($data) || ! is_array($data['lessons'] ?? null)) {
            throw ValidationException::withMessages(['file' => '資料檔中沒有 lessons。']);
        }

        /** @var array<string, mixed> $normalized 所有文字以 NFC 儲存（docs/SPEC.md 第 8 節） */
        $normalized = self::normalize($data);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function isVolumeJson(array $data): bool
    {
        return isset($data['language']) || isset($data['lessons'][0]['title_zh']) || isset($data['lessons'][0]['vocabulary'][0]['text']);
    }

    /**
     * 例：book_title「新住民語文學習教材 印尼語第2冊」與選擇的第 1 冊不同時擋下。
     *
     * @throws ValidationException
     */
    private static function checkBookTitle(mixed $title, string $language, int $volume): void
    {
        if (! is_string($title)) {
            return;
        }

        $names = Language::pluck('name_zh', 'code');
        $other = $names->except($language)->first(fn (string $name) => str_contains($title, $name));
        $volumeInTitle = preg_match('/第\s*(\d+)\s*冊/u', $title, $match) === 1 ? (int) $match[1] : null;

        if (($other !== null && ! str_contains($title, (string) $names->get($language))) || ($volumeInTitle !== null && $volumeInTitle !== $volume)) {
            throw ValidationException::withMessages(['file' => "資料檔是「{$title}」，與選擇的{$names->get($language)}第 {$volume} 冊不同。"]);
        }
    }

    /**
     * 「課文與詞彙.json」轉成 volume.json 的欄位。課文不讀。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function convert(array $data): array
    {
        $errors = [];
        $lessons = [];
        foreach (array_values($data['lessons']) as $i => $lesson) {
            $number = is_array($lesson) && is_int($lesson['lesson'] ?? null) ? $lesson['lesson'] : null;
            $label = $number === null ? '第 '.($i + 1).' 個課' : "第 {$number} 課";
            if (! is_array($lesson) || $number === null) {
                $errors[] = "{$label}沒有課次（lesson）。";

                continue;
            }

            $title = $lesson['title'] ?? null;
            $vocabulary = [];
            foreach (is_array($lesson['vocabulary'] ?? null) ? array_values($lesson['vocabulary']) : [] as $j => $word) {
                $text = is_array($word) ? self::targetText($word) : null;
                if ($text === null) {
                    $errors[] = "{$label}第 ".($j + 1).' 個詞：找不到目標語的欄位（chinese、page 以外要剛好有一個欄位）。';

                    continue;
                }
                $vocabulary[] = ['text' => $text, 'translation_zh' => $word['chinese'] ?? null, 'page' => $word['page'] ?? null];
            }

            $lessons[] = [
                'lesson' => $number,
                'title_zh' => is_array($title) ? ($title['chinese'] ?? null) : $title,
                'title_native' => is_array($title) ? self::targetText($title) : null,
                'vocabulary' => $vocabulary,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return array_filter([
            'textbook' => is_string($data['book_title'] ?? null) ? $data['book_title'] : null,
            'lessons' => $lessons,
        ], fn ($value) => $value !== null);
    }

    /**
     * chinese、page 以外唯一的文字欄位，例：{"indonesian": "merah", "chinese": "紅色", "page": 6} 的 merah。
     *
     * @param  array<mixed>  $fields
     */
    private static function targetText(array $fields): ?string
    {
        $others = array_filter(
            array_diff_key($fields, array_flip(['chinese', 'page'])),
            fn ($value) => is_string($value),
        );

        return count($others) === 1 ? (string) reset($others) : null;
    }

    private static function defaultTextbook(string $language, int $volume): string
    {
        return '新住民語文學習教材 '.Language::whereKey($language)->value('name_zh')."第{$volume}冊";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Volume
     *
     * @throws ValidationException
     */
    private static function validate(array $data, ?string $directory): array
    {
        $validator = Validator::make($data, [
            'language' => ['required', 'string', Rule::exists('languages', 'code')],
            'volume' => ['required', 'integer', 'min:1'],
            'textbook' => ['required', 'string'],
            'authors' => ['required', 'array', 'min:1'],
            'authors.*.name' => ['required', 'string'],
            'license' => ['required', 'string'],
            // repo 的資料檔附插圖，要寫插圖的署名；在後台上傳的插圖，署名在上傳時填寫
            'images' => [$directory === null ? 'nullable' : 'required', 'array'],
            'images.authors' => ['required_with:images', 'array', 'min:1'],
            'images.authors.*.name' => ['required', 'string'],
            'images.license' => ['required_with:images', 'string'],
            'images.source' => ['required_with:images', 'string'],
            'lessons' => ['required', 'array', 'min:1'],
            'lessons.*.lesson' => ['required', 'integer', 'min:1', 'distinct'],
            'lessons.*.title_zh' => ['required', 'string'],
            'lessons.*.title_native' => ['nullable', 'string'],
            'lessons.*.vocabulary' => ['required', 'array', 'min:1', 'max:200'],
            'lessons.*.vocabulary.*.text' => ['required', 'string', 'max:200'],
            'lessons.*.vocabulary.*.translation_zh' => ['required', 'string', 'max:200'],
            'lessons.*.vocabulary.*.page' => ['nullable', 'integer', 'min:1'],
            'lessons.*.vocabulary.*.image' => ['nullable', 'string'],
        ], attributes: [
            'lessons.*.lesson' => '課次',
            'lessons.*.title_zh' => '中文課名',
            'lessons.*.vocabulary' => '詞彙',
            'lessons.*.vocabulary.*.text' => '目標語的詞',
            'lessons.*.vocabulary.*.translation_zh' => '中文意思',
            'lessons.*.vocabulary.*.page' => '頁碼',
        ]);
        $validator->after(function ($validator) use ($data, $directory) {
            foreach ($data['lessons'] ?? [] as $i => $lesson) {
                $keys = array_map(fn ($word) => Textbook::wordKey((string) ($word['text'] ?? '')), $lesson['vocabulary'] ?? []);
                foreach (array_diff_assoc($keys, array_unique($keys)) as $key) {
                    $validator->errors()->add("lessons.{$i}.vocabulary", "第 {$lesson['lesson']} 課的「{$key}」重複");
                }
                if ($directory === null) {
                    continue;
                }
                foreach ($lesson['vocabulary'] ?? [] as $word) {
                    $image = $word['image'] ?? null;
                    if (is_string($image) && (str_contains($image, '..') || ! is_file("{$directory}/{$image}"))) {
                        $validator->errors()->add("lessons.{$i}.vocabulary", "第 {$lesson['lesson']} 課找不到圖檔 {$image}");
                    }
                }
            }
        });

        $volume = $validator->validate();
        $volume['images'] ??= null;
        $volume['lessons'] = array_map(fn (array $lesson) => [
            ...$lesson,
            'title_native' => $lesson['title_native'] ?? null,
            'vocabulary' => array_map(fn (array $word) => $word + ['page' => null, 'image' => null], $lesson['vocabulary']),
        ], $volume['lessons']);

        /** @var Volume $volume */
        return $volume;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return Normalizer::normalize($value, Normalizer::FORM_C);
        }

        return is_array($value) ? array_map(self::normalize(...), $value) : $value;
    }
}
