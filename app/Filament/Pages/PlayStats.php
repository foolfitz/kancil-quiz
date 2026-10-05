<?php

namespace App\Filament\Pages;

use App\Curriculum\PlayCounts;
use App\Models\Language;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 人氣統計（docs/SPEC.md A-04）：每課、每個遊戲的試玩與課堂次數（App\Curriculum\PlayCounts）。
 * 只有次數，沒有個資，管理員與審核者都能看。
 *
 * @phpstan-import-type Row from PlayCounts
 */
class PlayStats extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = '人氣統計';

    protected static ?string $slug = 'play-stats';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['admin', 'curator']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('試玩是訪客不經過活動直接玩教材的一課：按下「開始」算一次開始，玩到最後算一次玩完；同一個分頁重新整理或「再玩一次」不重複算。課堂是老師直接用教材題組建立的活動，每一次作答（包括再玩一次）都算，只算到作答紀錄的保存期限；複製或挑詞做成的題組不算在這一課。日期依台灣時間。'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator {
                // 期間沒有選（清掉篩選）就是全部
                $days = $filters['period']['value'] ?? null;
                $rows = app(PlayCounts::class)
                    ->summary(filled($days) ? (int) $days : null, $filters['language']['value'] ?? null)
                    ->sortBy([
                        [$sortColumn ?? 'trial_starts', $sortDirection === 'asc' ? 'asc' : 'desc'],
                        ['language', 'asc'],
                        ['volume', 'asc'],
                        ['lesson', 'asc'],
                        ['game', 'asc'],
                    ]);

                return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('title')->label('課')
                    ->description(fn (array $record): string => $record['language'], position: 'above')
                    ->url(fn (array $record): string => $record['url'], shouldOpenInNewTab: true),
                TextColumn::make('game')->label('遊戲'),
                ColumnGroup::make('試玩', [
                    TextColumn::make('trial_starts')->label('開始')->numeric()->sortable(),
                    TextColumn::make('trial_finishes')->label('玩完')->numeric()->sortable(),
                    TextColumn::make('trial_rate')->label('玩完比例')
                        ->state(fn (array $record): ?string => $record['trial_starts'] > 0
                            ? round(100 * $record['trial_finishes'] / $record['trial_starts']).'%'
                            : null)
                        ->placeholder('—'),
                ]),
                ColumnGroup::make('課堂', [
                    TextColumn::make('class_starts')->label('作答')->numeric()->sortable(),
                    TextColumn::make('class_finishes')->label('玩完')->numeric()->sortable(),
                ]),
            ])
            ->filters([
                SelectFilter::make('period')->label('期間')
                    ->options(['7' => '最近 7 天', '30' => '最近 30 天'])
                    ->placeholder('全部')
                    ->default('30'),
                SelectFilter::make('language')->label('語言')
                    ->options(fn () => Language::enabled()->pluck('name_zh', 'code')->all()),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->defaultPaginationPageOption(25)
            ->defaultSort('trial_starts', 'desc')
            ->emptyStateHeading('這段期間還沒有人玩教材');
    }
}
