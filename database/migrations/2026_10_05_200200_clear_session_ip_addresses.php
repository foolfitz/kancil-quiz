<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 工作階段不再記錄 IP 與瀏覽器（App\Support\SessionHandler，docs/SPEC.md 第 11 節）；清掉已經記下的。
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sessions')->update(['ip_address' => null, 'user_agent' => null]);
    }

    public function down(): void
    {
        //
    }
};
