<?php

namespace App\Http\Controllers;

use App\Corpus\SetCopier;
use App\Models\Set;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * 複製題組到自己的題組，之後可以改編（docs/SPEC.md T-13、T-17、第 5 節）。
 */
class SetCopyController extends Controller
{
    public function __invoke(Request $request, Set $set, SetCopier $copier): RedirectResponse
    {
        // 透過同事的分享連結複製：持有有效的 token 即可（T-17）
        $token = $request->input('token');
        $shared = $set->visibility === 'unlisted'
            && $set->share_token !== null
            && is_string($token)
            && hash_equals($set->share_token, $token);

        if (! $shared) {
            Gate::authorize('copy', $set);
        }

        $copy = $copier->copy($set, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => '已複製到我的題組，可以開始改編']);

        return to_route('sets.edit', $copy);
    }
}
