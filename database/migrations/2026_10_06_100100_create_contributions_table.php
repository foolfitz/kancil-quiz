<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 創作者頁面的貢獻日曆（docs/SPEC.md T-20、第 5 節）：每位老師、每個題組、台灣時間的每一天產生了幾個版本，
// 只有次數。題組版本本身 30 天後可能被清除（第 5 節），所以另外累計；顯示時只算目前公開的題組。
// 既有的版本在這裡補算一次。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('set_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('revisions')->default(0);
            $table->unique(['user_id', 'set_id', 'date']);
            $table->index(['user_id', 'date']);
        });

        $timezone = (string) config('kancil.timezone', 'Asia/Taipei');
        $rows = [];
        DB::table('set_revisions')->whereNotNull('created_by')->orderBy('id')
            ->select(['set_id', 'created_by', 'created_at'])
            ->lazyById(500)
            ->each(function (object $revision) use (&$rows, $timezone) {
                $date = CarbonImmutable::parse((string) $revision->created_at, 'UTC')->setTimezone($timezone)->toDateString();
                $key = "{$revision->created_by}|{$revision->set_id}|{$date}";
                $rows[$key] ??= ['user_id' => (int) $revision->created_by, 'set_id' => (string) $revision->set_id, 'date' => $date, 'revisions' => 0];
                $rows[$key]['revisions']++;
            });

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('contributions')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
