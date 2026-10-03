<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids as BaseHasUlids;

/**
 * 以 ULID 當主鍵，有兩點與 Laravel 預設不同：
 *
 * - 使用 ULID 的標準形式（大寫的 Crockford Base32），與交換格式一致（docs/SPEC.md 第 6 節）。
 * - 隨機部分每次都重新取 80 位元。Laravel 的 Str::ulid() 在同一毫秒內只把隨機部分加一，
 *   相鄰建立的活動 ID 就能互相推算；活動連結本身是存取憑證（3.4），不可被猜到。
 */
trait HasUlids
{
    use BaseHasUlids;

    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function newUniqueId(): string
    {
        $time = (int) floor(microtime(true) * 1000);
        $timestamp = '';
        for ($i = 0; $i < 10; $i++) {
            $timestamp = self::CROCKFORD[$time % 32].$timestamp;
            $time = intdiv($time, 32);
        }

        // 256 是 32 的倍數，取餘數不會造成偏差。
        $random = '';
        foreach (str_split(random_bytes(16)) as $byte) {
            $random .= self::CROCKFORD[ord($byte) % 32];
        }

        return $timestamp.$random;
    }
}
