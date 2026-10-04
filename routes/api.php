<?php

use App\Http\Controllers\Api\AttemptController;
use App\Http\Controllers\Api\PlaybackController;
use App\Http\Controllers\SetExportController;
use Illuminate\Support\Facades\Route;

// 學生端與公開 API，不需登入（docs/SPEC.md 10.3）。
Route::prefix('v1')->middleware('throttle:student-api')->name('api.')->group(function () {
    Route::get('activities/{activity}', [PlaybackController::class, 'show'])->name('activities.show');
    Route::post('activities/{activity}/attempts', [AttemptController::class, 'store'])->name('attempts.store');
    Route::post('attempts/{attempt}/responses', [AttemptController::class, 'responses'])->name('attempts.responses');
    Route::post('attempts/{attempt}/complete', [AttemptController::class, 'complete'])->name('attempts.complete');
    // 公開題組的 zip（開放資料，T-15）
    Route::get('sets/{set}/export', [SetExportController::class, 'openData'])->name('sets.export');
});
