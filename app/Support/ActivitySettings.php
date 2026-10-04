<?php

namespace App\Support;

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

/**
 * 活動的兩個設定（docs/SPEC.md 3.4）：學生要不要輸入名字或座號，以及開放與截止時間。
 * 兩者互不相關，建立後隨時都能修改。
 *
 * 「要輸入名字」存成 `mode = assignment`，沿用活動播放格式（6.6）原有的欄位。
 * 老師端以 `kancil.timezone` 的當地時間輸入與顯示（`Y-m-d\TH:i`，即 `<input type="datetime-local">` 的格式），
 * 資料庫存 UTC。
 *
 * @phpstan-type Settings array{require_label: bool, opens_at: string|null, closes_at: string|null}
 */
final class ActivitySettings
{
    public const FORMAT = 'Y-m-d\TH:i';

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'require_label' => ['sometimes', 'boolean'],
            'opens_at' => ['nullable', 'date_format:'.self::FORMAT],
            'closes_at' => [
                'bail',
                'nullable',
                'date_format:'.self::FORMAT,
                // 比較要用當地時間換算，不能用 after:now（會以 UTC 解讀輸入）
                function (string $attribute, mixed $value, Closure $fail): void {
                    $closes = self::parse(is_string($value) ? $value : null);
                    $opens = self::parse(is_string(request()->input('opens_at')) ? request()->input('opens_at') : null);
                    if ($closes === null) {
                        return;
                    }
                    if ($opens !== null && $closes <= $opens) {
                        $fail('截止時間要晚於開放時間。');
                    } elseif ($closes->isPast()) {
                        $fail('截止時間已經過了。要讓學生不能再玩，請按「立即截止」。');
                    }
                },
            ],
        ];
    }

    /**
     * 驗證過的輸入轉成活動的欄位。只轉換有送來的欄位：時間送 null 表示清除，沒送則不變。
     *
     * @param  array<string, mixed>  $data
     * @return array{mode?: string, opens_at?: CarbonImmutable|null, closes_at?: CarbonImmutable|null}
     */
    public static function attributes(array $data): array
    {
        $attributes = [];
        if (array_key_exists('require_label', $data)) {
            $attributes['mode'] = $data['require_label'] ? 'assignment' : 'practice';
        }
        foreach (['opens_at', 'closes_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = self::parse(is_string($data[$key]) ? $data[$key] : null);
            }
        }

        return $attributes;
    }

    /**
     * 給老師端頁面的設定。
     *
     * @return array{require_label: bool, opens_at: string|null, closes_at: string|null, status: string}
     */
    public static function of(Activity $activity): array
    {
        return [
            'require_label' => $activity->requiresLabel(),
            'opens_at' => self::format($activity->opens_at),
            'closes_at' => self::format($activity->closes_at),
            'status' => $activity->status(),
        ];
    }

    /**
     * 建立活動的預設值：不必輸入名字，今天 00:00 開放、第 7 天 23:59 截止（共一週）。
     *
     * @return Settings
     */
    public static function defaults(): array
    {
        $today = CarbonImmutable::now(self::timezone())->startOfDay();

        return [
            'require_label' => false,
            'opens_at' => $today->format(self::FORMAT),
            'closes_at' => $today->addDays(6)->setTime(23, 59)->format(self::FORMAT),
        ];
    }

    public static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $time = CarbonImmutable::createFromFormat(self::FORMAT, $value, self::timezone());
        } catch (\Throwable) {
            return null;
        }

        return $time instanceof CarbonImmutable ? $time->setSecond(0)->utc() : null;
    }

    public static function format(?CarbonInterface $time): ?string
    {
        return $time?->toImmutable()->setTimezone(self::timezone())->format(self::FORMAT);
    }

    private static function timezone(): string
    {
        $zone = config('kancil.timezone');

        return is_string($zone) ? $zone : 'Asia/Taipei';
    }
}
