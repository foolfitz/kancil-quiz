<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 迷宮的遊戲 ID 由 maze-chase 改為 maze-quiz（docs/SPEC.md 7.5）。
// 活動連結用的是活動 ID，已經發出的連結不受影響。含已刪除的活動，還原時才不會對不上遊戲。
return new class extends Migration
{
    public function up(): void
    {
        DB::table('activities')->where('game_id', 'maze-chase')->update(['game_id' => 'maze-quiz']);
    }

    public function down(): void
    {
        DB::table('activities')->where('game_id', 'maze-quiz')->update(['game_id' => 'maze-chase']);
    }
};
