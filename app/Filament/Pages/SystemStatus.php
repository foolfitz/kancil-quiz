<?php

namespace App\Filament\Pages;

use App\Support\Health;
use App\Support\StatusCheck;
use App\Support\SystemStatus as Status;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * 系統狀態（docs/SPEC.md A-02）：儲存空間、最近一次備份、最近一次 kancil:prune 與排程是否在運作，
 * 由 App\Support\SystemStatus 算出。只有管理員能看。
 */
class SystemStatus extends Page
{
    protected static ?string $title = '系統狀態';

    protected static ?string $slug = 'system-status';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public function content(Schema $schema): Schema
    {
        $status = app(Status::class);

        return $schema->components([
            Text::make('每天凌晨 3 點由主機上的 docker/backup.sh 備份，4 點由 scheduler 服務執行 kancil:prune 清除過了保存期限的資料（docs/deploy.md）。這一頁讀的是它們留下的紀錄，重新整理就會更新。'),
            $this->section('儲存空間', $status->storage(), 'storage'),
            $this->section('備份', $status->backup(), 'backup'),
            $this->section('資料的清除（kancil:prune）', $status->prune(), 'prune'),
            $this->section('排程（scheduler 服務）', $status->scheduler(), 'scheduler'),
        ]);
    }

    private function section(string $heading, StatusCheck $check, string $key): Section
    {
        $entries = [];
        foreach (array_values($check->details) as $i => $value) {
            $entries[] = TextEntry::make("{$key}_{$i}")
                ->label(array_keys($check->details)[$i])
                ->state($value);
        }

        return Section::make($heading)
            ->icon(match ($check->health) {
                Health::Ok => Heroicon::OutlinedCheckCircle,
                Health::Warning => Heroicon::OutlinedExclamationTriangle,
                Health::Danger => Heroicon::OutlinedXCircle,
            })
            ->iconColor($check->health->color())
            ->schema([
                Callout::make($check->summary)
                    ->description($check->advice)
                    ->color($check->health->color())
                    ->columnSpanFull(),
                ...$entries,
            ])
            ->columns(3);
    }
}
