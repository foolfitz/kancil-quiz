<?php

namespace App\Http\Requests\Settings;

use App\Models\Set;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Normalizer;

/**
 * 創作者資料（docs/SPEC.md T-20）：署名名稱與網址、預設授權、學校、教的語言、簡介。
 * 除了預設授權都是選填；署名名稱空白表示用帳號的名字。文字以 NFC 儲存（第 8 節），簡介是純文字。
 */
class CreatorProfileUpdateRequest extends FormRequest
{
    public const BIO_MAX = 500;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attribution_name' => ['nullable', 'string', 'max:100'],
            'attribution_url' => ['nullable', 'string', 'max:300', 'url:http,https'],
            'default_license' => ['required', Rule::in(Set::LICENSES)],
            'school' => ['nullable', 'string', 'max:100'],
            'teaching_languages' => ['nullable', 'array', 'max:20'],
            'teaching_languages.*' => ['string', 'distinct', Rule::exists('languages', 'code')],
            'bio' => ['nullable', 'string', 'max:'.self::BIO_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'attribution_name' => '署名名稱',
            'attribution_url' => '網址',
            'default_license' => '預設授權',
            'school' => '學校',
            'teaching_languages' => '教的語言',
            'teaching_languages.*' => '教的語言',
            'bio' => '簡介',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['attribution_url.url' => '網址要以 http:// 或 https:// 開頭。'];
    }

    protected function prepareForValidation(): void
    {
        $text = fn (mixed $value) => is_string($value) && trim($value) !== ''
            ? (Normalizer::normalize(trim($value), Normalizer::FORM_C) ?: trim($value))
            : null;

        $this->merge([
            'attribution_name' => $text($this->input('attribution_name')),
            'attribution_url' => $text($this->input('attribution_url')),
            'school' => $text($this->input('school')),
            // 多行的簡介：保留換行，只去掉頭尾的空白
            'bio' => $text($this->input('bio')),
            'teaching_languages' => array_values(array_filter((array) $this->input('teaching_languages', []), 'is_string')),
        ]);
    }

    /**
     * 寫進 users 的欄位；空的清單存成 null。
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $data = $this->validated();
        $data['teaching_languages'] = ($data['teaching_languages'] ?? []) === [] ? null : array_values($data['teaching_languages']);

        return $data;
    }
}
