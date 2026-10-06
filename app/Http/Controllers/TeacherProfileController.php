<?php

namespace App\Http\Controllers;

use App\Corpus\SetCards;
use App\Models\Language;
use App\Models\Set;
use App\Models\User;
use App\Profile\Contributions;
use App\Profile\TeacherStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 創作者頁面（docs/SPEC.md T-20）：只給登入的老師看，和共備庫是同一群人。網址用 public_id（ULID），
 * 停用、已刪除（匿名化）的帳號與教材帳號回 404。只顯示創作者資料、由公開內容算出的貢獻統計，
 * 以及公開的題組；不顯示 email 或 Google 帳號的任何資料。
 */
class TeacherProfileController extends Controller
{
    public function show(Request $request, User $teacher): Response
    {
        abort_unless($teacher->hasProfilePage(), 404);

        $languages = Language::query()->whereIn('code', $teacher->teaching_languages ?? [])->orderBy('sort')->get(['code', 'name_zh', 'name_native']);

        return Inertia::render('teachers/Show', [
            'teacher' => [
                'id' => $teacher->public_id,
                'name' => $teacher->attributionName(),
                'url' => $teacher->attribution_url,
                'school' => $teacher->school,
                'languages' => $languages,
                'bio' => $teacher->bio,
            ],
            'isSelf' => $request->user()->is($teacher),
            'stats' => TeacherStats::for($teacher),
            'calendar' => Contributions::calendar($teacher),
            'sets' => SetCards::paginate(Set::listed()->where('owner_id', $teacher->id)),
        ]);
    }
}
