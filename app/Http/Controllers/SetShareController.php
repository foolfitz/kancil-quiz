<?php

namespace App\Http\Controllers;

use App\Corpus\SetViewData;
use App\Models\Set;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 題組的分享連結給同事（docs/SPEC.md T-17、3.2）：產生後題組改為 unlisted，持有連結的老師登入後
 * 可以檢視與複製；收回時改回 private，舊連結失效。再次產生會是新的連結。
 */
class SetShareController extends Controller
{
    public function store(Set $set): RedirectResponse
    {
        Gate::authorize('manage', $set);
        abort_if($set->isPublic(), 409, '已公開的題組不需要分享連結');

        if ($set->share_token === null) {
            $set->update(['visibility' => 'unlisted', 'share_token' => Str::random(32)]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => '已產生分享連結']);

        return back();
    }

    public function destroy(Set $set): RedirectResponse
    {
        Gate::authorize('manage', $set);

        if ($set->visibility === 'unlisted') {
            $set->update(['visibility' => 'private', 'share_token' => null]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => '已收回分享連結，已經複製出去的題組不受影響']);

        return back();
    }

    /**
     * 同事開啟分享連結。
     */
    public function show(Request $request, string $token): Response
    {
        $set = Set::where('share_token', $token)->where('visibility', 'unlisted')->firstOrFail();

        return Inertia::render('sets/Show', SetViewData::props($set, $request->user(), $token));
    }
}
