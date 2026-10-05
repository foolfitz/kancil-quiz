<?php

namespace App\Http\Controllers\Api;

use App\Corpus\ActivityPlayback;
use App\Corpus\SetContent;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;

/**
 * 活動播放格式（docs/SPEC.md 6.6）：活動設定加上題組最新版本，媒體為絕對網址。
 */
class PlaybackController extends Controller
{
    public function show(Activity $activity): JsonResponse
    {
        // 管理員停用的老師，活動連結也失效（docs/SPEC.md A-05）
        abort_if($activity->owner->isDisabled(), 404);
        $revision = $activity->set->currentRevision;
        abort_if($revision === null, 404, '這個活動還沒有內容');

        return response()->json(
            ActivityPlayback::payload($activity, $revision),
            options: SetContent::JSON_FLAGS,
        )->header('Cache-Control', 'no-cache');
    }
}
