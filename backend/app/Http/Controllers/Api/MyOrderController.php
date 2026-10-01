<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyOrderController extends Controller
{
    /**
     * The signed-in customer's orders, newest first, 15 a page. Checkouts that were never
     * paid are left out.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $orders = $user->orders()
            ->whereIn('payment_status', [PaymentStatus::Paid, PaymentStatus::Refunded])
            ->with(['restaurant', 'items.modifiers', 'statusEvents'])
            ->latest('placed_at')
            ->latest('id')
            ->paginate(15);

        return OrderResource::collection($orders);
    }
}
