<?php

namespace App\Media;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * 每位老師上傳的媒體總量上限（docs/SPEC.md A-05、第 9 節）：以轉檔後的大小合計。上限預設是
 * config('kancil.upload_quota_mb')，管理員可以在後台為個別老師調整（users.upload_quota_mb）；管理員自己不受限制。
 * 沒有用到的媒體由 kancil:prune 清除後就不再計算。
 *
 * 上傳時的檢查（MediaController）、編輯頁的「已上傳多少／上限」與後台的使用者列表都從這裡取得，算法只有一份。
 */
final class UploadQuota
{
    public const MB = 1024 * 1024;

    // 用到上限的這個比例就提醒快到上限了；resources/js/lib/uploadQuota.ts 的 WARN_RATIO 相同
    public const WARN_RATIO = 0.8;

    private function __construct(
        public readonly int $usedBytes,
        // null 表示不限制（管理員）
        public readonly ?int $limitMb,
    ) {}

    /**
     * $usedBytes 已經算好時（後台列表用 withSum 一次算完）可以直接帶入，不再查詢。
     */
    public static function of(User $user, ?int $usedBytes = null): self
    {
        return new self(
            $usedBytes ?? (int) $user->media()->sum('bytes'),
            $user->hasRole('admin') ? null : ($user->upload_quota_mb ?? (int) config('kancil.upload_quota_mb')),
        );
    }

    public function limitBytes(): ?int
    {
        return $this->limitMb === null ? null : $this->limitMb * self::MB;
    }

    /**
     * 已經用掉的比例，不限制時是 null。管理員把上限調到比已上傳的還少時會超過 1。
     */
    public function ratio(): ?float
    {
        $limit = $this->limitBytes();

        return $limit === null ? null : ($limit === 0 ? INF : $this->usedBytes / $limit);
    }

    public function isFull(): bool
    {
        $limit = $this->limitBytes();

        return $limit !== null && $this->usedBytes >= $limit;
    }

    public function isNearlyFull(): bool
    {
        return ! $this->isFull() && $this->ratio() >= self::WARN_RATIO;
    }

    /**
     * 到達上限時拒絕上傳。
     */
    public function ensureNotFull(): void
    {
        if ($this->isFull()) {
            throw ValidationException::withMessages([
                'file' => "你上傳的檔案已經到達上限（{$this->describe()}），請聯絡網站管理員。",
            ]);
        }
    }

    /**
     * 例：「37 MB／200 MB」；不限制時「37 MB（不限）」。
     */
    public function label(): string
    {
        $used = self::format($this->usedBytes);

        return $this->limitMb === null ? "{$used}（不限）" : "{$used}／{$this->limitMb} MB";
    }

    /**
     * 例：「已上傳 37 MB／200 MB」。
     */
    public function describe(): string
    {
        return '已上傳 '.$this->label();
    }

    /**
     * 編輯頁與上傳回應中的資料（resources/js/lib/uploadQuota.ts）。
     *
     * @return array{used_bytes: int, limit_bytes: int|null}
     */
    public function toArray(): array
    {
        return ['used_bytes' => $this->usedBytes, 'limit_bytes' => $this->limitBytes()];
    }

    /**
     * 不到 1 MB 顯示 KB，不到 10 MB 顯示一位小數，其餘取整數；resources/js/lib/uploadQuota.ts 的 formatBytes() 相同。
     */
    public static function format(int $bytes): string
    {
        if ($bytes < self::MB) {
            return round($bytes / 1024).' KB';
        }

        $mb = $bytes / self::MB;

        return ($mb < 10 ? round($mb, 1) : round($mb)).' MB';
    }
}
