<?php

namespace Tests;

use App\Models\SetEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        $this->assertQuizMediaReferencesAreInSync();

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * 問答題引用的媒體另外記在 set_entry_media，由 SetEntry 儲存時同步（docs/SPEC.md 第 5 節）。
     * 每個用到資料庫的測試結束時都檢查一次對照表與 payload 一致，有新的程式寫入 payload 卻沒有同步時，
     * 不論它在哪個流程中都會被抓到。在 RefreshDatabase 還原交易之前執行。
     * 對照表有外鍵，只比對還存在的媒體。
     */
    protected function assertQuizMediaReferencesAreInSync(): void
    {
        if (! isset($this->app) || ! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }
        if (! Schema::hasTable('set_entry_media') || ! Schema::hasTable('set_entries')) {
            return;
        }

        $recorded = DB::table('set_entry_media')->orderBy('media_id')->get()
            ->groupBy('set_entry_id')
            ->map(fn ($rows) => $rows->pluck('media_id')->all())
            ->all();
        $referenced = SetEntry::query()->whereNotNull('payload')->select(['id', 'payload'])->get()
            ->mapWithKeys(fn (SetEntry $entry) => [$entry->id => array_values(array_unique($entry->mediaIds()))]);
        $existing = DB::table('media')->whereIn('id', $referenced->flatten()->unique())->pluck('id')->flip();
        $expected = $referenced
            ->map(fn (array $ids) => array_values(array_filter($ids, fn (string $id) => $existing->has($id))))
            ->filter()
            ->map(function (array $ids) {
                sort($ids);

                return $ids;
            })
            ->all();
        ksort($recorded);
        ksort($expected);

        $this->assertSame($expected, $recorded, 'set_entry_media 與 set_entries.payload 不一致：有程式寫入 payload 卻沒有同步對照表（SetEntry::syncMedia()）。');
    }
}
