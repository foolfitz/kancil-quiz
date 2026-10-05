<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 學生端播放頁（docs/SPEC.md S-01）：開啟連結就能玩，不需帳號。
 */
class PlayerController extends Controller
{
    public function show(Request $request, Activity $activity): View
    {
        // 管理員停用的老師，活動連結也失效（docs/SPEC.md A-05）
        abort_if($activity->owner->isDisabled(), 404);

        return view('player', [
            'activity' => $activity,
            // 預覽不建立作答紀錄
            'preview' => $request->boolean('preview'),
        ]);
    }
}
