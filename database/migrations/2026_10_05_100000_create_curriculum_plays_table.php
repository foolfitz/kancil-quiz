<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 教材試玩的人氣統計（docs/SPEC.md S-06、A-04）：每課、每個遊戲、每天（台灣時間）只累計次數，
// 不存逐筆紀錄、IP 或任何識別資料。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_ref_id')->constrained()->cascadeOnDelete();
            $table->string('game_id');
            $table->date('date');
            $table->unsignedInteger('starts')->default(0);
            $table->unsignedInteger('finishes')->default(0);
            $table->unique(['curriculum_ref_id', 'game_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_plays');
    }
};
