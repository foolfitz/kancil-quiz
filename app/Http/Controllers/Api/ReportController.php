<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityReported;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * 播放頁的檢舉（docs/SPEC.md S-07）。任何人都能用 Google 註冊，活動連結可能被拿來散布不當內容。
 * 只存活動與原因，不存 IP；另外限流（AppServiceProvider 的 reports）。
 */
class ReportController extends Controller
{
    public function store(Request $request, Activity $activity): JsonResponse
    {
        abort_if($activity->owner->isDisabled(), 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => '請寫下這個活動有什麼問題。',
            'reason.max' => '請在 1000 個字以內。',
        ]);

        $report = $activity->reports()->create(['reason' => trim($data['reason']), 'created_at' => now()]);

        // 寄信失敗（例如 SMTP 設定錯誤）不影響檢舉，管理員仍會在後台看到
        try {
            Notification::send(User::role('admin')->whereNull('disabled_at')->get(), new ActivityReported($report));
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->json(['message' => '已經送出，謝謝你。管理員會盡快處理。'], 201);
    }
}
