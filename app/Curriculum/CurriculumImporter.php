<?php

namespace App\Curriculum;

use App\Corpus\SetWriter;
use App\Media\MediaProcessor;
use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Normalizer;

/**
 * 匯入一冊教材的課名與詞彙（docs/SPEC.md 3.6）。資料格式見 database/curriculum/README.md。
 *
 * - 每一課建立或更新冊課對照，並有一個教材題組：公開的詞彙組，由教材帳號擁有（Textbook::owner()）。
 * - 可以重複執行。詞條以目標語文字對應，題目的 ID 不變；內容與上一版相同時不產生新版本。
 * - 審核者在網站上修正過的教材題組不覆寫（$force 除外），以免蓋掉修正：請先把修正寫回資料檔。
 * - 插圖只在詞條還沒有圖片時匯入（媒體不可變，第 9 節）；要換圖時用 $refreshImages。插圖的署名會依資料檔更新。
 * - 只匯入課名與詞彙，資料檔中有課文也不讀（D-4）。
 */
class CurriculumImporter
{
    /** @var array<string, Media> 同一次匯入中，同一個圖檔只轉檔一次（例如兩課都有的詞） */
    private array $images = [];

    public function __construct(private SetWriter $writer, private MediaProcessor $media) {}

    /**
     * @return list<array{lesson: int, title: string, words: int, result: 'created'|'updated'|'unchanged'|'skipped'}>
     */
    public function import(string $directory, bool $force = false, bool $refreshImages = false): array
    {
        $directory = rtrim($directory, '/');
        $volume = $this->read($directory);
        $owner = Textbook::owner();
        $this->images = [];

        return array_values(array_map(
            fn (array $lesson) => $this->importLesson($volume, $lesson, $directory, $owner, $force, $refreshImages),
            $volume['lessons'],
        ));
    }

    /**
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $lesson
     * @return array{lesson: int, title: string, words: int, result: 'created'|'updated'|'unchanged'|'skipped'}
     */
    private function importLesson(array $volume, array $lesson, string $directory, User $owner, bool $force, bool $refreshImages): array
    {
        $ref = CurriculumRef::firstOrNew([
            'language_code' => $volume['language'],
            'volume' => $volume['volume'],
            'lesson' => $lesson['lesson'],
        ]);
        $ref->fill(['title_zh' => $lesson['title_zh'], 'title_native' => $lesson['title_native'] ?? null])->save();

        $set = $ref->set;
        $result = 'created';
        if ($set === null) {
            $set = $owner->sets()->create(['kind' => 'vocab', 'title' => Textbook::title($ref), 'language_code' => $ref->language_code, 'license' => $volume['license']]);
            $ref->update(['set_id' => $set->id]);
        } elseif (! $force && (int) $set->currentRevision?->created_by !== $owner->id) {
            $result = 'skipped';
        } else {
            $result = 'unchanged';
        }

        $summary = ['lesson' => $ref->lesson, 'title' => Textbook::title($ref), 'words' => count($lesson['vocabulary'])];
        if ($result === 'skipped') {
            return [...$summary, 'result' => $result];
        }

        $before = $set->current_revision_id;
        $set->update([
            'title' => Textbook::title($ref),
            'description' => "取自《{$volume['textbook']}》第 {$ref->lesson} 課的詞彙。",
            'license' => $volume['license'],
            'authors' => $volume['authors'],
            'visibility' => 'public',
            'review_status' => 'approved',
        ]);
        $set->curriculumRefs()->sync([$ref->id]);

        $existing = $set->entries()->with('item.media')->get()->keyBy(fn (SetEntry $entry) => self::key($entry->item->text));
        $entries = array_map(function (array $word) use ($volume, $ref, $existing, $directory, $owner, $refreshImages) {
            $entry = $existing->get(self::key($word['text']));
            $media = $entry?->item->media ?? collect();
            $image = $media->firstWhere('pivot.role', 'image');
            if ($word['image'] === null) {
                $image = null;
            } elseif ($image === null || $refreshImages) {
                $image = $this->image("{$directory}/{$word['image']}", $owner, $volume['images']);
            } elseif ($image->uploaded_by === $owner->id) {
                // 檔案不可變，署名可以修正（docs/SPEC.md 第 9 節）；審核者上傳的圖不動
                $image->update(Arr::only($volume['images'], ['authors', 'license', 'source']));
            }

            return [
                'id' => $entry?->id,
                'item' => [
                    'text' => $word['text'],
                    'romanization' => $entry?->item->romanization,
                    'translation_zh' => $word['translation_zh'],
                    // 音檔不在資料檔中，保留在網站上加的
                    'audio_ids' => $media->where('pivot.role', 'audio')->pluck('id')->all(),
                    'image_id' => $image?->id,
                    'authors' => $volume['authors'],
                    'license' => $volume['license'],
                    'source' => "《{$volume['textbook']}》第 {$ref->lesson} 課".($word['page'] === null ? '' : "，課本第 {$word['page']} 頁"),
                ],
            ];
        }, $lesson['vocabulary']);

        $this->writer->write($set, ['faces' => Textbook::FACES, 'entries' => $entries], $owner);

        if ($result === 'unchanged' && $set->refresh()->current_revision_id !== $before) {
            $result = 'updated';
        }

        return [...$summary, 'result' => $result];
    }

    /**
     * @param  array{authors: list<array{name: string}>, license: string, source: string}  $attribution
     */
    private function image(string $path, User $owner, array $attribution): Media
    {
        return $this->images[$path] ??= $this->media->imageFromPath($path, $owner, $attribution);
    }

    /**
     * 詞條的對應鍵：與答案比對相同，不分大小寫、忽略頭尾與重複的空白（docs/SPEC.md 第 8 節）。
     */
    private static function key(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function read(string $directory): array
    {
        $file = "{$directory}/volume.json";
        if (! is_file($file)) {
            throw ValidationException::withMessages(['volume' => "找不到 {$file}"]);
        }

        $data = self::normalize(json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));

        $validator = Validator::make(is_array($data) ? $data : [], [
            'language' => ['required', 'string', Rule::exists('languages', 'code')],
            'volume' => ['required', 'integer', 'min:1'],
            'textbook' => ['required', 'string'],
            'authors' => ['required', 'array', 'min:1'],
            'authors.*.name' => ['required', 'string'],
            'license' => ['required', 'string'],
            'images' => ['required', 'array'],
            'images.authors' => ['required', 'array', 'min:1'],
            'images.authors.*.name' => ['required', 'string'],
            'images.license' => ['required', 'string'],
            'images.source' => ['required', 'string'],
            'lessons' => ['required', 'array', 'min:1'],
            'lessons.*.lesson' => ['required', 'integer', 'min:1', 'distinct'],
            'lessons.*.title_zh' => ['required', 'string'],
            'lessons.*.title_native' => ['nullable', 'string'],
            'lessons.*.vocabulary' => ['required', 'array', 'min:1', 'max:200'],
            'lessons.*.vocabulary.*.text' => ['required', 'string', 'max:200'],
            'lessons.*.vocabulary.*.translation_zh' => ['required', 'string', 'max:200'],
            'lessons.*.vocabulary.*.page' => ['nullable', 'integer', 'min:1'],
            'lessons.*.vocabulary.*.image' => ['nullable', 'string'],
        ]);
        $validator->after(function ($validator) use ($data, $directory) {
            foreach ($data['lessons'] ?? [] as $i => $lesson) {
                $keys = array_map(fn ($word) => self::key((string) ($word['text'] ?? '')), $lesson['vocabulary'] ?? []);
                foreach (array_diff_assoc($keys, array_unique($keys)) as $key) {
                    $validator->errors()->add("lessons.{$i}.vocabulary", "第 {$lesson['lesson']} 課的「{$key}」重複");
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
        $volume['lessons'] = array_map(fn (array $lesson) => [
            ...$lesson,
            'vocabulary' => array_map(fn (array $word) => $word + ['page' => null, 'image' => null], $lesson['vocabulary']),
        ], $volume['lessons']);

        return $volume;
    }

    /**
     * 所有文字以 NFC 儲存（docs/SPEC.md 第 8 節）。
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return Normalizer::normalize($value, Normalizer::FORM_C);
        }

        return is_array($value) ? array_map(self::normalize(...), $value) : $value;
    }
}
