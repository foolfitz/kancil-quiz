<?php

namespace App\Http\Controllers;

use App\Support\PageMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 隱私權政策與使用條款（docs/SPEC.md 第 11 節）。內容寫在 Vue 頁面中，營運者、聯絡方式與保存期限從設定帶入，
 * 架站的人改 .env 就好。
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('legal/Privacy', [
            ...$this->site(),
            'retention' => config('kancil.retention'),
            'meta' => PageMeta::make('隱私權政策', '我們向老師與學生蒐集哪些資料、怎麼使用、保存多久。學生不必註冊，不存 IP，不用第三方追蹤碼。', route('privacy')),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('legal/Terms', [
            ...$this->site(),
            'uploadQuotaMb' => config('kancil.upload_quota_mb'),
            'meta' => PageMeta::make('使用條款', '使用 Kancil Quiz 的規則：老師上傳的內容、公開題組的授權、禁止的行為與帳號的停用。', route('terms')),
        ]);
    }

    /**
     * @return array{operator: string|null, contactEmail: string|null, sourceUrl: string}
     */
    private function site(): array
    {
        return [
            'operator' => config('kancil.operator'),
            'contactEmail' => config('kancil.contact_email'),
            'sourceUrl' => 'https://github.com/foolfitz/kancil-quiz',
        ];
    }
}
