<?php

namespace App\Support;

/**
 * 系統狀態頁上一個項目的燈號（docs/SPEC.md A-02、App\Support\SystemStatus）。
 */
enum Health: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Danger = 'danger';

    public function label(): string
    {
        return match ($this) {
            self::Ok => '正常',
            self::Warning => '注意',
            self::Danger => '有問題',
        };
    }

    /**
     * Filament 的顏色名稱。
     */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warning => 'warning',
            self::Danger => 'danger',
        };
    }
}
