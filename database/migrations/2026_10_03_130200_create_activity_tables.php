<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 活動與作答紀錄（docs/SPEC.md 3.4、3.5、第 5 節、7.4）。不存 IP。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('set_id')->constrained();
            $table->string('game_id');
            $table->string('game_version');
            $table->json('options');
            $table->string('mode', 12)->default('practice'); // practice、assignment
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('set_revision_id')->constrained();
            $table->unsignedInteger('seed');
            $table->string('player_label')->nullable();
            $table->char('token_hash', 64);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('correct_count')->nullable(); // 伺服器判定；不計分的遊戲為 null
            $table->unsignedSmallInteger('round_count');
            $table->integer('game_score')->nullable(); // 遊戲回報，只供顯示
            $table->unsignedInteger('duration_ms')->nullable();
            $table->index(['activity_id', 'started_at']);
        });

        Schema::create('attempt_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('attempt_id')->constrained()->cascadeOnDelete();
            $table->ulid('entry_id'); // 版本內容中的 entry，不設外鍵
            $table->json('presented');
            $table->json('selected')->nullable();
            $table->boolean('correct')->nullable(); // 伺服器判定
            $table->boolean('client_correct')->nullable(); // 遊戲回報，用來發現遊戲的判定錯誤
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at');
            $table->index(['attempt_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_responses');
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('activities');
    }
};
