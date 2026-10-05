<?php

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * 刪除帳號前，輸入自己的 email 確認。老師用 Google 登入，沒有密碼可以確認。
 * 管理員不能刪除自己的帳號，要先由其他管理員拿掉管理員的角色。
 */
class ProfileDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->hasRole('admin');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (Str::lower(trim((string) $value)) !== Str::lower($this->user()->email)) {
                    $fail('請輸入你的 email 確認。');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirmation.required' => '請輸入你的 email 確認。'];
    }
}
