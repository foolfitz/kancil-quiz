<?php

namespace App\Corpus;

use App\Models\Activity;
use App\Models\SetRevision;

/**
 * 活動播放格式（docs/SPEC.md 6.6）：活動設定加上題組的版本內容，媒體為絕對網址。
 * 學生端 API 與老師的「建立前預覽」共用。
 */
class ActivityPlayback
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(Activity $activity, SetRevision $revision): array
    {
        return [
            'format' => 'kancil-activity',
            'version' => 1,
            'id' => $activity->id,
            'game' => [
                'id' => $activity->game_id,
                'version' => $activity->game_version,
                'options' => (object) $activity->options,
            ],
            'mode' => $activity->mode,
            'opens_at' => $activity->opens_at?->toIso8601String(),
            'closes_at' => $activity->closes_at?->toIso8601String(),
            'set_revision_id' => $revision->id,
            'set' => MediaUrls::absolutize($revision->content()),
        ];
    }
}
