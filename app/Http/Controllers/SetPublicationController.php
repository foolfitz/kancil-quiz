<?php

namespace App\Http\Controllers;

use App\Models\Set;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * 申請把題組公開到共備庫（docs/SPEC.md T-12）。審核者通過後才會公開（C-01）。
 */
class SetPublicationController extends Controller
{
    public function store(Request $request, Set $set): RedirectResponse
    {
        Gate::authorize('manage', $set);
        abort_if($set->isPublic() || $set->review_status === 'pending', 409, '這個題組已經公開或正在審核');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        abort_if($set->entries()->doesntExist(), 422, '題組還沒有內容');

        $set->update(['review_status' => 'pending']);
        $set->reviews()->create([
            'set_revision_id' => $set->current_revision_id,
            'user_id' => $request->user()->id,
            'action' => 'requested',
            'note' => $data['note'] ?? null,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => '已送出公開申請，審核者通過後就會出現在共備庫']);

        return back();
    }

    /**
     * 撤回申請，或把自己已公開的題組下架。
     */
    public function destroy(Request $request, Set $set): RedirectResponse
    {
        Gate::authorize('manage', $set);
        abort_unless($set->isPublic() || $set->review_status === 'pending', 409, '這個題組沒有公開，也沒有在審核');

        $set->update([
            'review_status' => 'none',
            'visibility' => $set->isPublic() ? 'private' : $set->visibility,
        ]);
        $set->reviews()->create([
            'set_revision_id' => $set->current_revision_id,
            'user_id' => $request->user()->id,
            'action' => 'withdrawn',
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => '已撤回，已經複製出去的題組不受影響']);

        return back();
    }
}
