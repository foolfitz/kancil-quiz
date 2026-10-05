<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * 系統狀態頁上的一個項目（備份、資料的清除、排程），由 App\Support\SystemStatus 產生。
 */
final readonly class StatusCheck
{
    /**
     * @param  string  $summary  一句話說明目前的狀態
     * @param  string|null  $advice  有問題時要查什麼、做什麼
     * @param  CarbonImmutable|null  $at  最近一次執行的時間（UTC），沒有紀錄時為 null
     * @param  array<string, string>  $details  其他要顯示的資料，標籤 => 內容
     */
    public function __construct(
        public Health $health,
        public string $summary,
        public ?string $advice = null,
        public ?CarbonImmutable $at = null,
        public array $details = [],
    ) {}
}
