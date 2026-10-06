<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreatorProfileUpdateRequest;
use App\Models\Language;
use App\Models\Set;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 設定頁的「創作者資料」（docs/SPEC.md T-20）：署名名稱與網址、預設授權、學校、教的語言、簡介。
 * 署名會自動填進之後寫下的作者（題組匯出時的擁有者、修改複製來的詞條、上傳的媒體）；
 * 已經寫下的署名不會回頭改（第 5 節）。
 */
class CreatorProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Creator', [
            'profile' => [
                'attribution_name' => $user->attribution_name,
                'attribution_url' => $user->attribution_url,
                'default_license' => $user->defaultLicense(),
                'school' => $user->school,
                'teaching_languages' => $user->teaching_languages ?? [],
                'bio' => $user->bio,
            ],
            'licenses' => Set::LICENSES,
            // 教的語言不限平台已開放的語言：老師可能教還沒開放的語言
            'languages' => Language::query()->orderBy('sort')->get(['code', 'name_zh', 'name_native']),
            'bioMax' => CreatorProfileUpdateRequest::BIO_MAX,
            'profileUrl' => $user->profileUrl(),
        ]);
    }

    public function update(CreatorProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->profile());

        Inertia::flash('toast', ['type' => 'success', 'message' => '創作者資料已更新。']);

        return to_route('creator.edit');
    }
}
