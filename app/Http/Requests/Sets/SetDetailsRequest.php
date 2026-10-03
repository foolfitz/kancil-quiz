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
    public const LICENSES = ['CC-BY-4.0', 'CC-BY-SA-4.0', 'CC0-1.0'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('set') === null;

        return [
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(Set::KINDS)],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'language_code' => ['required', Rule::exists('languages', 'code')->where('enabled', true)],
            'license' => ['required', Rule::in(self::LICENSES)],
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
        ];
    }
}
