<?php

namespace App\Filament\Resources\MenuItems\Pages;

use App\Filament\Resources\MenuItems\MenuItemResource;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMenuItem extends CreateRecord
{
    protected static string $resource = MenuItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var Restaurant $restaurant */
        $restaurant = Filament::getTenant();

        // New items go to the end of their category.
        $data['sort_order'] = (int) $restaurant->menuItems()->where('category_id', $data['category_id'])->max('sort_order') + 1;

        return $data;
    }
}
