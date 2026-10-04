<?php

return [

    /*
    | 老師輸入與檢視活動開放、截止時間所用的時區（docs/SPEC.md 3.4）。
    | 資料庫與 API 一律用 UTC，只在老師端的表單與畫面上換算。
    */
    'timezone' => env('KANCIL_TIMEZONE', 'Asia/Taipei'),

];
