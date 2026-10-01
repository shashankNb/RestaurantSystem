<?php

namespace App\Filament\Resources\SpecialHours\Pages;

use App\Filament\Resources\SpecialHours\SpecialHourResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSpecialHours extends ManageRecords
{
    protected static string $resource = SpecialHourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => SpecialHourResource::normaliseHours($data)),
        ];
    }
}
