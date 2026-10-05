<?php

namespace App\Http\Requests\Sets;

use App\Models\Set;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 題組的基本資料：標題、說明、語言、授權（建立時另外選擇種類）。
 */
class SetDetailsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $set = $this->route('set');
        $creating = $set === null;

        return [
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(Set::KINDS)],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'language_code' => ['required', Rule::exists('languages', 'code')->where('enabled', true)],
            // 編輯時也接受原本的授權：審核者修正教材題組時，授權維持原教材的
            'license' => ['required', Rule::in($set instanceof Set ? [...Set::LICENSES, $set->license] : Set::LICENSES)],
            // 從教材挑詞建立詞彙組（docs/SPEC.md T-18），只在建立時使用；是否屬於教材題組由 SetController 檢查
            'textbook_entries' => [$creating ? 'nullable' : 'prohibited', 'array', 'max:200'],
            'textbook_entries.*' => ['ulid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => '題組種類',
            'title' => '標題',
            'description' => '說明',
            'language_code' => '語言',
            'license' => '授權',
            'tags' => '標籤',
            'tags.*' => '標籤',
            'curriculum_ref_ids.*' => '教材冊課',
            'textbook_entries' => '教材的詞',
        ];
    }
}
