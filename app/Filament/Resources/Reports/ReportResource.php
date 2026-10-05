<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Models\Report;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * 播放頁的檢舉（docs/SPEC.md S-07、A-05），只有管理員能看。處理方式：到使用者停用老師的帳號，或標為已處理。
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static ?string $modelLabel = '檢舉';

    protected static ?string $pluralModelLabel = '檢舉';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    // 選單上顯示還沒處理的件數
    public static function getNavigationBadge(): ?string
    {
        $count = Report::whereNull('resolved_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
        ];
    }
}
