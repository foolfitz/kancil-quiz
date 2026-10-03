<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;
use Normalizer;

/**
 * 所有文字欄位寫入前以 NFC 正規化（docs/SPEC.md 第 5、8 節）。越南文輸入法可能產生 NFD。
 */
class NormalizeUnicode extends TransformsRequest
{
    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (! is_string($value) || Normalizer::isNormalized($value, Normalizer::FORM_C)) {
            return $value;
        }

        return Normalizer::normalize($value, Normalizer::FORM_C);
    }
}
