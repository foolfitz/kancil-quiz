<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 老師一律用 Google 帳號登入，第一次登入就建立帳號（docs/SPEC.md T-02、T-03、D-6）：
// 平台不管老師的密碼，只有管理員保留密碼；邀請制註冊拿掉。管理員可以停用帳號（A-05）。
// 老師刪除帳號時不真的刪除使用者，改為匿名化（anonymized_at，第 5 節）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique();
            $table->string('password')->nullable()->change();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
        });

        Schema::dropIfExists('invitations');
    }

    public function down(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('email')->nullable();
            $table->string('role', 16)->default('teacher');
            $table->string('note')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'disabled_at', 'anonymized_at']);
        });
    }
};
