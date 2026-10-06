<?php

namespace App\Support;

/**
 * 產生公開識別碼用的 ULID（docs/SPEC.md 第 5 節）。Model 的主鍵經由 App\Models\Concerns\HasUlids 用它；
 * 不是主鍵的公開識別碼（例如使用者的 public_id）直接呼叫 make()。
 *
 * - 使用 ULID 的標準形式（大寫的 Crockford Base32），與交換格式一致（第 6 節）。
 * - 隨機部分每次都重新取 80 位元。Laravel 的 Str::ulid() 在同一毫秒內只把隨機部分加一，
 *   相鄰建立的活動 ID 就能互相推算；活動連結本身是存取憑證（3.4），不可被猜到。
 */
final class Ulid
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function make(): string
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
