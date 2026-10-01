<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\OrderStatus;
use App\Http\Resources\StaffOrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The kitchen's order queue and the actions on it. Orders are addressed by public ID.
 */
class OrderController extends StaffController
{
    /**
     * Paid orders the kitchen still has to act on (placed, accepted, preparing, ready, out
     * for delivery), oldest first. `status` narrows it: `?status=placed,accepted`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $restaurant = $this->restaurant($request);
        $statuses = $this->statuses($request);

        $orders = $restaurant->orders()
            ->whereIn('status', $statuses)
            ->with('items.modifiers')
            ->orderBy('placed_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        // One restaurant (and one load of its opening hours) for every order's deadline.
        foreach ($orders as $order) {
            $order->setRelation('restaurant', $restaurant);
        }

        return StaffOrderResource::collection($orders);
    }

    public function accept(Request $request, Order $order, OrderService $orders): StaffOrderResource
    {
        Gate::authorize('manage', $order);

        $data = $request->validate([
            'prep_minutes' => ['required', 'integer', 'min:5', 'max:180'],
        ], [
            'prep_minutes.required' => 'Choose how long the order will take.',
            'prep_minutes.min' => 'Choose a prep time of at least 5 minutes.',
            'prep_minutes.max' => 'Choose a prep time of 3 hours or less.',
        ]);

        return $this->respond($orders->accept($order, (int) $data['prep_minutes'], $this->staff($request)));
    }

    public function reject(Request $request, Order $order, OrderService $orders): StaffOrderResource
    {
        Gate::authorize('manage', $order);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ], [
            'reason.required' => 'Say why, so the customer understands. For example: “We’ve run out of pork.”',
        ]);

        return $this->respond($orders->reject($order, trim((string) $data['reason']), $this->staff($request)));
    }

    /**
     * Moves an order along: preparing, ready, out_for_delivery, completed or cancelled.
     */
    public function status(Request $request, Order $order, OrderService $orders): StaffOrderResource
    {
        Gate::authorize('manage', $order);

        $data = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled'])],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        return $this->respond($orders->advance($order, OrderStatus::from((string) $data['status']), $this->staff($request), $note ?: null));
    }

    private function respond(Order $order): StaffOrderResource
    {
        return new StaffOrderResource($order->refresh()->load('items.modifiers'));
    }

    private function staff(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return list<OrderStatus>
     */
    private function statuses(Request $request): array
    {
        $requested = array_filter(explode(',', (string) $request->query('status', '')));

        if ($requested === []) {
            return OrderStatus::active();
        }

        return array_values(array_filter(
            array_map(fn (string $status): ?OrderStatus => OrderStatus::tryFrom(trim($status)), $requested),
        ));
    }
}
