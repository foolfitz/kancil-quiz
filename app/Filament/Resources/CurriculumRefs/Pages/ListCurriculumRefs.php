<?php

namespace App\Filament\Resources\CurriculumRefs\Pages;

use App\Filament\Resources\CurriculumRefs\CurriculumRefResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurriculumRefs extends ListRecords
{
    protected static string $resource = CurriculumRefResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
