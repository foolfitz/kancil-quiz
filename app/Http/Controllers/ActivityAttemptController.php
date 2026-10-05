<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Attempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * 老師在成績頁刪除一次作答（docs/SPEC.md T-11），例如自己用正式連結試玩留下的。
 * 直接刪除，逐題的作答一起刪除；成績與答錯率隨之重算。
 */
class ActivityAttemptController extends Controller
{
    public function destroy(Activity $activity, Attempt $attempt): RedirectResponse
    {
        Gate::authorize('manage', $activity);

        $attempt->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => '已刪除這次作答']);

        return back();
    }
}
