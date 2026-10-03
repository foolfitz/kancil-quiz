<?php

namespace App\Filament\Resources\Languages;

use App\Filament\Resources\Languages\Pages\EditLanguage;
use App\Filament\Resources\Languages\Pages\ListLanguages;
use App\Filament\Resources\Languages\Schemas\LanguageForm;
use App\Filament\Resources\Languages\Tables\LanguagesTable;
use App\Models\Language;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * 七種新住民語文固定存在，這裡只調整名稱與是否開放（docs/SPEC.md 1.4、附錄 A）。
 */
class LanguageResource extends Resource
{
    protected static ?string $model = Language::class;

    protected static ?string $modelLabel = '語言';

    protected static ?string $pluralModelLabel = '語言';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public static function form(Schema $schema): Schema
    {
        return LanguageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LanguagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLanguages::route('/'),
            'edit' => EditLanguage::route('/{record}/edit'),
        ];
    }
}
