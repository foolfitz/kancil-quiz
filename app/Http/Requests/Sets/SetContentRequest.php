<?php

namespace App\Http\Requests\Sets;

use App\Models\Set;
use App\Support\Licenses;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 題組的基本資料加上內容（詞彙組的詞條、問答組的題目），格式見 App\Corpus\SetWriter。
 */
class SetContentRequest extends SetDetailsRequest
{
    public const FACE_FIELDS = ['text', 'romanization', 'translation_zh', 'audio', 'image'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $set = $this->route('set');
        assert($set instanceof Set);

        $mediaExists = fn (string $kind) => Rule::exists('media', 'id')->where('kind', $kind);

        $content = $set->kind === 'vocab' ? [
            'faces' => ['required', 'array'],
            'faces.prompt' => ['required', 'array', 'min:1'],
            'faces.prompt.*' => ['distinct', Rule::in(self::FACE_FIELDS)],
            'faces.answer' => ['required', 'array', 'min:1'],
            'faces.answer.*' => ['distinct', Rule::in(self::FACE_FIELDS)],
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.id' => ['nullable', 'ulid'],
            'entries.*.item.text' => ['required', 'string', 'max:200'],
            'entries.*.item.romanization' => ['nullable', 'string', 'max:200'],
            'entries.*.item.translation_zh' => ['required', 'string', 'max:200'],
            'entries.*.item.audio_ids' => ['nullable', 'array', 'max:3'],
            'entries.*.item.audio_ids.*' => [$mediaExists('audio')],
            'entries.*.item.image_id' => ['nullable', $mediaExists('image')],
        ] : [
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.id' => ['nullable', 'ulid'],
            'entries.*.question.stem.text' => ['nullable', 'string', 'max:500'],
            'entries.*.question.stem.audio_id' => ['nullable', $mediaExists('audio')],
            'entries.*.question.stem.image_id' => ['nullable', $mediaExists('image')],
            'entries.*.question.options' => ['required', 'array', 'min:2', 'max:6'],
            'entries.*.question.options.*.id' => ['required', 'string', 'max:8'],
            'entries.*.question.options.*.text' => ['nullable', 'string', 'max:200'],
            'entries.*.question.options.*.image_id' => ['nullable', $mediaExists('image')],
            'entries.*.question.options.*.correct' => ['required', 'boolean'],
        ];

        // 媒體的署名（docs/SPEC.md 第 9 節），只送自己上傳的媒體，由 App\Corpus\MediaCredits 寫回
        $credits = [
            'media_credits' => ['nullable', 'array', 'max:600'],
            'media_credits.*.id' => ['required', Rule::exists('media', 'id')],
            'media_credits.*.author' => ['nullable', 'string', 'max:100'],
            'media_credits.*.source' => ['nullable', 'string', 'max:300'],
            'media_credits.*.license' => ['nullable', Rule::in(Licenses::all())],
        ];

        // 題組層級的冊課與標籤（docs/SPEC.md 3.6、6.3），共備庫依此搜尋（T-13）
        $meta = [
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'distinct', 'max:30'],
            'curriculum_ref_ids' => ['nullable', 'array', 'max:20'],
            'curriculum_ref_ids.*' => [
                'integer', 'distinct',
                Rule::exists('curriculum_refs', 'id')->where('language_code', (string) $this->input('language_code')),
            ],
        ];

        return [...parent::rules(), ...$meta, ...$credits, ...$content];
    }

    /**
     * Schema 表達不了、但老師輸入時就該擋下的規則（docs/SPEC.md 6.5）。
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $set = $this->route('set');
            assert($set instanceof Set);

            if ($set->kind === 'vocab') {
                foreach (['prompt' => '題目', 'answer' => '答案'] as $side => $name) {
                    $fields = (array) $this->input("faces.{$side}", []);
                    if (in_array('text', $fields, true) && in_array('translation_zh', $fields, true)) {
                        $validator->errors()->add("faces.{$side}", "{$name}面不能同時使用目標語文字與中文意思");
                    }
                }

                return;
            }

            foreach ((array) $this->input('entries', []) as $i => $entry) {
                $number = $i + 1;
                $stem = $entry['question']['stem'] ?? [];
                if (blank($stem['text'] ?? null) && blank($stem['audio_id'] ?? null) && blank($stem['image_id'] ?? null)) {
                    $validator->errors()->add("entries.{$i}.question.stem", "第 {$number} 題的題幹至少要有文字、音檔或圖片");
                }

                $options = $entry['question']['options'] ?? [];
                $correct = count(array_filter($options, fn ($option) => filter_var($option['correct'] ?? false, FILTER_VALIDATE_BOOLEAN)));
                if ($correct !== 1) {
                    $validator->errors()->add("entries.{$i}.question.options", "第 {$number} 題要恰好標示一個正解");
                }

                foreach ($options as $j => $option) {
                    if (blank($option['text'] ?? null) && blank($option['image_id'] ?? null)) {
                        $validator->errors()->add("entries.{$i}.question.options.{$j}", "第 {$number} 題的選項要有文字或圖片");
                    }
                }

                $ids = array_column($options, 'id');
                if (count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add("entries.{$i}.question.options", "第 {$number} 題的選項代號重複");
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'entries.*.item.text' => '目標語文字',
            'entries.*.item.translation_zh' => '中文意思',
            'entries.*.item.romanization' => '羅馬拼寫',
            'entries.*.question.options' => '選項',
            'media_credits.*.author' => '作者',
            'media_credits.*.source' => '出處',
            'media_credits.*.license' => '授權',
        ];
    }
}
