<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ActivityResultsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\SetController;
use App\Http\Controllers\SetCopyController;
use App\Http\Controllers\SetPublicationController;
use App\Http\Controllers\SetReviewController;
use App\Http\Controllers\SetShareController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// 學生端播放頁，不需登入
Route::get('p/{activity}', [PlayerController::class, 'show'])->name('play');

// 老師端（docs/SPEC.md 10.3）
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('sets', SetController::class);
    Route::post('sets/{set}/copy', SetCopyController::class)->name('sets.copy');
    Route::post('sets/{set}/share', [SetShareController::class, 'store'])->name('sets.share.store');
    Route::delete('sets/{set}/share', [SetShareController::class, 'destroy'])->name('sets.share.destroy');
    Route::get('shared/{token}', [SetShareController::class, 'show'])->name('sets.shared');
    Route::post('sets/{set}/publication', [SetPublicationController::class, 'store'])->name('sets.publication.store');
    Route::delete('sets/{set}/publication', [SetPublicationController::class, 'destroy'])->name('sets.publication.destroy');
    Route::get('library', LibraryController::class)->name('library');
    Route::get('reviews', [SetReviewController::class, 'index'])->name('reviews.index');
    Route::post('sets/{set}/review', [SetReviewController::class, 'store'])->name('sets.review');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');

    Route::get('sets/{set}/activities/create', [ActivityController::class, 'create'])->name('activities.create');
    Route::get('sets/{set}/activities/preview', [ActivityController::class, 'preview'])->name('activities.preview');
    Route::post('sets/{set}/activities', [ActivityController::class, 'store'])->name('activities.store');
    Route::get('activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
    Route::get('activities/{activity}/results', ActivityResultsController::class)->name('activities.results');
    Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
});

require __DIR__.'/settings.php';
