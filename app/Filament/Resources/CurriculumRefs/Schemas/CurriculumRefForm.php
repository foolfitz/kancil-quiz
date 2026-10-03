<?php

namespace App\Filament\Resources\CurriculumRefs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CurriculumRefForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('language_code')
                    ->label('語言')
                    ->relationship('language', 'name_zh')
                    ->required(),
                TextInput::make('volume')->label('冊')->required()->integer()->minValue(1),
                TextInput::make('lesson')->label('課')->required()->integer()->minValue(1),
                TextInput::make('title_zh')->label('課名')->maxLength(255),
            ]);
    }
}
