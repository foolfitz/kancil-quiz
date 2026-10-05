<?php

namespace App\Http\Controllers\Settings;

use App\Auth\AccountDeletion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'google' => $user->google_id !== null,
            // 管理員不能刪除自己的帳號（ProfileDeleteRequest）
            'canDelete' => ! $user->hasRole('admin'),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * 刪除帳號：匿名化，不真的刪除使用者（App\Auth\AccountDeletion）。
     */
    public function destroy(ProfileDeleteRequest $request, AccountDeletion $deletion): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $deletion->delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
