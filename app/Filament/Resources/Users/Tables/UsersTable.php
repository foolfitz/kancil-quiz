<?php

namespace App\Filament\Resources\Users\Tables;

use App\Auth\AccountDeletion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('姓名')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('roles.name')->label('角色')->badge(),
                TextColumn::make('login')->label('登入方式')
                    ->state(fn (User $record): string => implode('、', array_filter([
                        $record->google_id !== null ? 'Google' : null,
                        $record->hasPassword() ? '密碼' : null,
                    ])))
                    ->placeholder('—'),
                TextColumn::make('status')->label('狀態')->badge()
                    ->state(fn (User $record): ?string => match (true) {
                        $record->isAnonymized() => '已刪除',
                        $record->isDisabled() => '停用',
                        default => null,
                    })
                    ->color(fn (?string $state): string => $state === '停用' ? 'danger' : 'gray'),
                TextColumn::make('sets_count')->label('題組數')->counts('sets')->sortable(),
                TextColumn::make('created_at')->label('註冊時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('disabled_at')->label('停用')->nullable(),
                TernaryFilter::make('anonymized_at')->label('已刪除')->nullable()
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('anonymized_at'),
                        false: fn (Builder $query) => $query->whereNull('anonymized_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (User $record): bool => ! $record->isAnonymized()),
                // 停用（docs/SPEC.md A-05）：不能登入、已經登入的會被登出；活動連結與分享連結失效，題組不再列在共備庫
                Action::make('disable')->label('停用')->color('danger')
                    ->visible(fn (User $record): bool => ! $record->isDisabled() && ! $record->isAnonymized() && $record->isNot(auth()->user()))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => "停用 {$record->name}？")
                    ->modalDescription('停用後不能登入，已經登入的會被登出；他的活動連結與分享連結失效，公開的題組不再列在共備庫。資料都保留，可以再恢復。')
                    ->action(function (User $record) {
                        $record->forceFill(['disabled_at' => now()])->save();
                        Notification::make()->title("已停用 {$record->name}")->success()->send();
                    }),
                Action::make('enable')->label('恢復')
                    ->visible(fn (User $record): bool => $record->isDisabled())
                    ->action(function (User $record) {
                        $record->forceFill(['disabled_at' => null])->save();
                        Notification::make()->title("已恢復 {$record->name}")->success()->send();
                    }),
                // 和老師在設定頁刪除帳號相同，是匿名化（App\Auth\AccountDeletion）。管理員要先拿掉管理員的角色
                Action::make('anonymize')->label('刪除帳號')->color('danger')
                    ->visible(fn (User $record): bool => ! $record->isAnonymized() && ! $record->hasRole('admin'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => "刪除 {$record->name} 的帳號？")
                    ->modalDescription('清除名字、email 與登入方式；活動連結立刻失效，私人題組、活動與作答在 30 天後永久刪除。公開的題組留在共備庫，照舊署名。無法復原。')
                    ->action(function (User $record, AccountDeletion $deletion) {
                        $deletion->delete($record);
                        Notification::make()->title('帳號已刪除')->success()->send();
                    }),
            ]);
    }
}
