<?php

namespace App\Filament\Resources\ModifierGroups\Pages;

use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateModifierGroup extends CreateRecord
{
    protected static string $resource = ModifierGroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var Restaurant $restaurant */
        $restaurant = Filament::getTenant();

        $data['sort_order'] = (int) $restaurant->modifierGroups()->max('sort_order') + 1;

        return $data;
    }
}
