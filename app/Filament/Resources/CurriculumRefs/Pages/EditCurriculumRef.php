<?php

namespace App\Filament\Resources\CurriculumRefs\Pages;

use App\Filament\Resources\CurriculumRefs\CurriculumRefResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCurriculumRef extends EditRecord
{
    protected static string $resource = CurriculumRefResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
