<?php

use App\Models\Media;
use App\Models\SetEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 問答題引用的媒體（docs/SPEC.md 第 5 節）。問答組的題目存在 set_entries.payload（JSON），題幹的音檔與圖片、
// 選項的圖片以媒體 ID 引用（SetEntry::mediaIds()）。要知道哪些媒體用在哪些問答題，本來得把平台上所有問答題的
// payload 讀出來（創作者頁面的統計、kancil:prune 的媒體清除），所以像詞條的 item_media 一樣另外記成一張對照表，
// payload 寫入時由 SetEntry 同步。既有的題目在這裡補算一次。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('set_entry_media', function (Blueprint $table) {
            $table->foreignUlid('set_entry_id')->constrained()->cascadeOnDelete();
            // 媒體只在沒有任何引用時才清除（App\Support\Pruner），還有題目引用時不能刪
            $table->foreignUlid('media_id')->constrained('media');
            $table->primary(['set_entry_id', 'media_id']);
            $table->index('media_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('set_entry_media');
    }

    /**
     * 把既有問答題的 payload 引用的媒體寫進對照表，與 SetEntry::syncMedia() 用同一個 mediaIds()。
     */
    public function backfill(): void
    {
        SetEntry::query()->whereNotNull('payload')->select(['id', 'payload'])
            ->chunkById(500, function (Collection $entries) {
                $rows = [];
                foreach ($entries as $entry) {
                    foreach (array_unique($entry->mediaIds()) as $id) {
                        $rows[] = ['set_entry_id' => $entry->id, 'media_id' => $id];
                    }
                }
                if ($rows === []) {
                    return;
                }

                // 對照表有外鍵，payload 中已經沒有對應媒體的 ID（本來就是斷掉的引用）放不進去
                $existing = Media::whereIn('id', array_unique(array_column($rows, 'media_id')))->pluck('id')->flip();
                $rows = array_values(array_filter($rows, fn (array $row) => $existing->has($row['media_id'])));

                DB::table('set_entry_media')->insert($rows);
            });
    }
};
