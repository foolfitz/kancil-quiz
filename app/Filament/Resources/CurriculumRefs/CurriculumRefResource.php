<?php

namespace App\Filament\Resources\CurriculumRefs;

use App\Filament\Resources\CurriculumRefs\Pages\CreateCurriculumRef;
use App\Filament\Resources\CurriculumRefs\Pages\EditCurriculumRef;
use App\Filament\Resources\CurriculumRefs\Pages\ListCurriculumRefs;
use App\Filament\Resources\CurriculumRefs\Schemas\CurriculumRefForm;
use App\Filament\Resources\CurriculumRefs\Tables\CurriculumRefsTable;
use App\Models\CurriculumRef;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * 教材的冊與課對照，只存對照資訊，不存教材內容（docs/SPEC.md 3.6）。
 */
class CurriculumRefResource extends Resource
{
    protected static ?string $model = CurriculumRef::class;

    protected static ?string $modelLabel = '教材對照';

    protected static ?string $pluralModelLabel = '教材對照';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    public static function form(Schema $schema): Schema
    {
        return CurriculumRefForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculumRefsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurriculumRefs::route('/'),
            'create' => CreateCurriculumRef::route('/create'),
            'edit' => EditCurriculumRef::route('/{record}/edit'),
        ];
    }
}
