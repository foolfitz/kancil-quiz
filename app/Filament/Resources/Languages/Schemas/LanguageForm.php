<?php

namespace App\Filament\Resources\Languages\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LanguageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')->label('代碼')->disabled(),
                TextInput::make('name_zh')->label('中文名稱')->required(),
                TextInput::make('name_native')->label('原文名稱')->required(),
                TextInput::make('sort')->label('排序')->numeric()->required(),
                Toggle::make('enabled')
                    ->label('開放給老師使用')
                    ->helperText('未開放的語言不會出現在老師端的選單中。泰、柬、緬語需要額外的字型與斷詞工作（M6）。'),
            ]);
    }
}
