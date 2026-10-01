<?php

namespace App\Filament\Resources\MenuCategories\Pages;

use App\Filament\Resources\MenuCategories\MenuCategoryResource;
use App\Models\Restaurant;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageMenuCategories extends ManageRecords
{
    protected static string $resource = MenuCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    /** @var Restaurant $restaurant */
                    $restaurant = Filament::getTenant();

                    // New categories go to the end of the menu.
                    return [...$data, 'sort_order' => (int) $restaurant->menuCategories()->max('sort_order') + 1];
                }),
        ];
    }
}
