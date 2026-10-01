<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Resources\MenuItemResource;
use App\Http\Resources\ModifierOptionResource;
use App\Models\MenuItem;
use App\Models\ModifierOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The kitchen's switches: pause online ordering, and mark items and options sold out.
 * The public menu and restaurant endpoints show the change on their next request.
 */
class AvailabilityController extends StaffController
{
    public function updateRestaurant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_accepting_orders' => ['required', 'boolean'],
        ]);

        $restaurant = $this->restaurant($request);
        $restaurant->update(['is_accepting_orders' => (bool) $data['is_accepting_orders']]);

        return response()->json([
            'data' => [
                'slug' => $restaurant->slug,
                'is_accepting_orders' => $restaurant->is_accepting_orders,
            ],
        ]);
    }

    public function updateMenuItem(Request $request, MenuItem $menuItem): MenuItemResource
    {
        Gate::authorize('updateAvailability', $menuItem);

        $data = $request->validate(['is_available' => ['required', 'boolean']]);
        $menuItem->update(['is_available' => (bool) $data['is_available']]);

        return new MenuItemResource($menuItem->load('modifierGroups.options'));
    }

    public function updateModifierOption(Request $request, ModifierOption $modifierOption): ModifierOptionResource
    {
        Gate::authorize('updateAvailability', $modifierOption);

        $data = $request->validate(['is_available' => ['required', 'boolean']]);
        $modifierOption->update(['is_available' => (bool) $data['is_available']]);

        return new ModifierOptionResource($modifierOption);
    }
}
