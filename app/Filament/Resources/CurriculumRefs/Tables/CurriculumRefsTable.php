<?php

namespace App\Filament\Resources\CurriculumRefs\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CurriculumRefsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('language.name_zh')->label('語言'),
                TextColumn::make('volume')->label('冊')->sortable(),
                TextColumn::make('lesson')->label('課')->sortable(),
                TextColumn::make('title_zh')->label('課名')->searchable(),
                TextColumn::make('title_native')->label('目標語課名')->searchable(),
                TextColumn::make('set_id')->label('教材題組')->formatStateUsing(fn () => '已匯入')->placeholder('—'),
            ])
            ->defaultSort('volume')
            ->filters([
                SelectFilter::make('language_code')->label('語言')->relationship('language', 'name_zh'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
