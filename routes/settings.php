<?php

use App\Http\Controllers\Settings\CreatorProfileController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\EnsureUserHasPassword;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // 創作者資料（docs/SPEC.md T-20）：署名、預設授權、學校、教的語言、簡介
    Route::get('settings/creator', [CreatorProfileController::class, 'edit'])->name('creator.edit');
    Route::patch('settings/creator', [CreatorProfileController::class, 'update'])->name('creator.update');

    // 密碼、雙重驗證與 passkey：只給有密碼的帳號（管理員）
    Route::middleware(EnsureUserHasPassword::class)->group(function () {
        Route::get('settings/security', [SecurityController::class, 'edit'])
            ->middleware(RequirePassword::class)
            ->name('security.edit');

        Route::put('settings/password', [SecurityController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('user-password.update');
    });

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
