<?php

namespace App\Filament\Resources\Languages\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class LanguagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('代碼'),
                TextColumn::make('name_zh')->label('中文名稱'),
                TextColumn::make('name_native')->label('原文名稱'),
                TextColumn::make('script')->label('文字'),
                ToggleColumn::make('enabled')->label('開放'),
            ])
            ->defaultSort('sort')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
