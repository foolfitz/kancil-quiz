<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Games\GameRegistry;
use App\Models\Report;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['activity.set', 'activity.owner', 'resolver']))
            ->columns([
                TextColumn::make('created_at')->label('時間')->dateTime('Y-m-d H:i', config('kancil.timezone'))->sortable(),
                TextColumn::make('activity.set.title')->label('活動')
                    ->description(fn (Report $record): string => app(GameRegistry::class)->title($record->activity->game_id)
                        .($record->activity->trashed() ? '（老師已刪除）' : ''))
                    ->url(fn (Report $record): ?string => $record->activity->trashed() ? null : route('play', $record->activity), shouldOpenInNewTab: true),
                TextColumn::make('activity.owner.name')->label('老師')
                    ->description(fn (Report $record): string => $record->activity->owner->isDisabled() ? '已停用' : $record->activity->owner->email)
                    ->url(fn (Report $record): ?string => $record->activity->owner->isAnonymized() ? null : UserResource::getUrl('edit', ['record' => $record->activity->owner])),
                TextColumn::make('reason')->label('原因')->wrap(),
                TextColumn::make('resolved_at')->label('處理')
                    ->formatStateUsing(fn (Report $record): string => '已處理（'.($record->resolver->name ?? '—').'）')
                    ->placeholder('未處理'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('resolved_at')->label('已處理')->nullable()->default(false),
            ])
            ->recordActions([
                // 和使用者列表的「停用」相同（docs/SPEC.md A-05），同時把這件檢舉標為已處理
                Action::make('disableOwner')->label('停用老師')->color('danger')
                    ->visible(fn (Report $record): bool => ! $record->activity->owner->isDisabled() && ! $record->activity->owner->isAnonymized())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Report $record): string => "停用 {$record->activity->owner->name}？")
                    ->modalDescription('停用後不能登入，已經登入的會被登出；他的活動連結與分享連結失效，公開的題組不再列在共備庫。資料都保留，可以在「使用者」恢復。')
                    ->action(function (Report $record) {
                        $record->activity->owner->forceFill(['disabled_at' => now()])->save();
                        $record->update(['resolved_at' => now(), 'resolved_by' => auth()->id()]);
                    }),
                Action::make('resolve')->label('標為已處理')
                    ->visible(fn (Report $record): bool => $record->resolved_at === null)
                    ->action(fn (Report $record) => $record->update(['resolved_at' => now(), 'resolved_by' => auth()->id()])),
                Action::make('reopen')->label('改回未處理')->color('gray')
                    ->visible(fn (Report $record): bool => $record->resolved_at !== null)
                    ->action(fn (Report $record) => $record->update(['resolved_at' => null, 'resolved_by' => null])),
            ]);
    }
}
