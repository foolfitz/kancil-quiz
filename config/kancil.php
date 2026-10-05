<?php

return [

    /*
    | 老師輸入與檢視活動開放、截止時間所用的時區（docs/SPEC.md 3.4）。
    | 資料庫與 API 一律用 UTC，只在老師端的表單與畫面上換算。
    */
    'timezone' => env('KANCIL_TIMEZONE', 'Asia/Taipei'),

    /*
    | Vite 開發伺服器的 hot 檔，沒有設定時用 public/hot。E2E 指到不存在的檔案：
    | 本機同時開著 composer dev 時，E2E 仍用建置好的前端，不經過開發伺服器的 SSR。
    */
    'vite_hot_file' => env('VITE_HOT_FILE'),

    /*
    | 隱私權政策與使用條款上的營運者與聯絡方式（docs/SPEC.md 第 11 節）。架站的人要填自己的資料。
    */
    'operator' => env('KANCIL_OPERATOR'),
    'contact_email' => env('KANCIL_CONTACT_EMAIL'),

    /*
    | 每位老師上傳的媒體總量上限（MB，以轉檔後的大小計算，docs/SPEC.md 第 9 節）。管理員不受限制。
    */
    'upload_quota_mb' => (int) env('KANCIL_UPLOAD_QUOTA_MB', 200),

    /*
    | 資料的保存期限（docs/SPEC.md 第 5、9 節）。每天由排程 kancil:prune 清除（App\Support\Pruner）。
    */
    'retention' => [
        // 作答紀錄從開始作答起保存幾個月
        'attempt_months' => (int) env('KANCIL_ATTEMPT_RETENTION_MONTHS', 12),
        // 老師刪除的題組、活動與詞條，幾天後連同作答紀錄真正刪除
        'trashed_days' => 30,
        // 題組版本被新版本取代後至少保留幾天，修訂紀錄才看得到最近的修改
        'revision_days' => 30,
        // 上傳後還沒有用到的媒體保留幾天：老師可能還開著編輯頁，還沒儲存
        'media_grace_days' => 7,
    ],

];
