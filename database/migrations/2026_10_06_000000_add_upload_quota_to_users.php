<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 個別老師的上傳上限（docs/SPEC.md A-05、第 9 節）：管理員在後台調整；
// 空的就用 config('kancil.upload_quota_mb') 的預設值（App\Media\UploadQuota）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('upload_quota_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('upload_quota_mb');
        });
    }
};
