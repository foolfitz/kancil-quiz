<?php

namespace App\Filament\Resources\Invitations\Tables;

use App\Models\Invitation;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('note')->label('備註')->searchable(),
                TextColumn::make('email')->label('Email')->placeholder('不限'),
                TextColumn::make('role')->label('角色')->badge(),
                TextColumn::make('inviter.name')->label('邀請人'),
                TextColumn::make('status')
                    ->label('狀態')
                    ->state(fn (Invitation $record) => match (true) {
                        $record->accepted_at !== null => '已使用',
                        $record->expires_at->isPast() => '已過期',
                        default => '有效',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        '有效' => 'success',
                        '已使用' => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('expires_at')->label('到期')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteAction::make()->label('撤銷'),
            ]);
    }
}
