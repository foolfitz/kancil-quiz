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

];
