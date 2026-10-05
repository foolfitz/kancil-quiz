<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 播放頁的檢舉（docs/SPEC.md S-07、A-05）：只存活動與原因，不存 IP 或檢舉人的資料。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('activity_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
