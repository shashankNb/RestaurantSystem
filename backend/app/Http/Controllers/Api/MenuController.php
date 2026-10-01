<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    /**
     * Active categories → items → option groups → options, in menu order. Not cached, so
     * sold-out switches show straight away. Categories with no active items are left out.
     */
    public function show(Restaurant $restaurant): JsonResponse
    {
        $categories = $restaurant->menuCategories()
            ->active()
            ->ordered()
            ->with(['items' => fn (Relation $query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->with('modifierGroups.options')])
            ->get()
            ->filter(fn (MenuCategory $category): bool => $category->items->isNotEmpty())
            ->values();

        return response()->json([
            'data' => [
                'categories' => MenuCategoryResource::collection($categories),
            ],
        ]);
    }
}
