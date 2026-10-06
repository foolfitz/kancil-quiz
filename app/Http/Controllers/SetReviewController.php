<?php

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\SetReview;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 審核公開申請（docs/SPEC.md C-01）：通過後公開到共備庫，或附意見退回；已公開的題組可以下架。
 * 審核者只能審核負責的語言，而且不能審核自己的題組。
 */
class SetReviewController extends Controller
{
    /**
     * 待審的題組，以及最近的審核紀錄。
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['admin', 'curator']), 403);
        $languages = $user->reviewableLanguageCodes();

        $pending = Set::query()
            ->where('review_status', 'pending')
            ->when($languages !== null, fn ($query) => $query->whereIn('language_code', $languages)->where('owner_id', '!=', $user->id))
            ->with(['owner', 'language', 'reviews' => fn ($query) => $query->where('action', 'requested')])
            ->withCount('entries')
            ->get()
            ->map(fn (Set $set) => [
                'id' => $set->id,
                'kind' => $set->kind,
                'title' => $set->title,
                'language' => $set->language->name_zh,
                'owner' => $set->owner->attributionName(),
                'entries_count' => $set->entries_count,
                'requested_at' => $set->reviews->first()?->created_at->toIso8601String(),
                'note' => $set->reviews->first()?->note,
            ])
            ->sortBy('requested_at')
            ->values();

        $recent = SetReview::query()
            ->whereIn('action', ['approved', 'rejected', 'unpublished'])
            ->whereHas('set', fn ($query) => $query->when($languages !== null, fn ($query) => $query->whereIn('language_code', $languages)))
            ->with(['set:id,title', 'user:id,name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (SetReview $review) => [
                'set_id' => $review->set_id,
                'title' => $review->set->title,
                'action' => $review->action,
                'note' => $review->note,
                'user' => $review->user->name,
                'created_at' => $review->created_at->toIso8601String(),
            ]);

        return Inertia::render('reviews/Index', [
            'pending' => $pending,
            'recent' => $recent,
            'languages' => $languages === null
                ? null
                : $user->reviewLanguages()->pluck('name_zh')->all(),
        ]);
    }

    public function store(Request $request, Set $set): RedirectResponse
    {
        Gate::authorize('review', $set);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'unpublish'])],
            'note' => [Rule::requiredIf($request->input('decision') !== 'approve'), 'nullable', 'string', 'max:2000'],
        ], attributes: ['note' => '意見']);

        $decision = (string) $data['decision'];
        $expected = $decision === 'unpublish' ? $set->isPublic() : $set->review_status === 'pending';
        abort_unless($expected, 409, $decision === 'unpublish' ? '這個題組沒有公開' : '這個題組沒有在等待審核');

        /** @var User $reviewer */
        $reviewer = $request->user();
        [$attributes, $action, $message] = match ($decision) {
            // 公開後就不需要給同事的分享連結（T-17）
            'approve' => [['review_status' => 'approved', 'visibility' => 'public', 'share_token' => null], 'approved', '已通過，題組已公開到共備庫'],
            'reject' => [['review_status' => 'rejected'], 'rejected', '已退回，擁有者會看到你的意見'],
            default => [['review_status' => 'rejected', 'visibility' => 'private', 'share_token' => null], 'unpublished', '已下架'],
        };

        $set->update($attributes);
        $set->reviews()->create([
            'set_revision_id' => $set->current_revision_id,
            'user_id' => $reviewer->id,
            'action' => $action,
            'note' => $data['note'] ?? null,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
