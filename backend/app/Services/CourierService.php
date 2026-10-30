<?php

namespace App\Services;

use App\Data\DeliveryAddress;
use App\Delivery\CourierUnavailable;
use App\Delivery\DoorDash\DoorDashDelivery;
use App\Delivery\DoorDash\DoorDashGateway;
use App\Delivery\DoorDash\DoorDashRefused;
use App\Enums\CourierStatus;
use App\Enums\OrderStatus;
use App\Events\OrderUpdated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Delivery by DoorDash's drivers, for restaurants that switch it on with their own DoorDash
 * account:
 *
 * - At checkout, DoorDash prices the customer's address, or only confirms it can deliver
 *   there when customers pay the zone's fee. A price is reused for 4 minutes, inside the 5
 *   that DoorDash honours a quote for, so the checkout's total and the order's agree.
 * - When the kitchen accepts the order, a driver is booked for when it's ready (or for the
 *   time the customer chose): a fresh quote, accepted straight away.
 * - DoorDash's news (its webhook, or asking when that's missed) moves the order out for
 *   delivery once the driver has it, and to completed once it's delivered. A booking DoorDash
 *   refuses or calls off is shown to the kitchen, which can book again or deliver it.
 * - Cancelling the order calls off its driver, which DoorDash allows until one is assigned.
 */
final class CourierService
{
    /** How long a DoorDash price is reused, inside the 5 minutes DoorDash honours a quote. */
    private const PRICE_MINUTES = 4;

    /** How long DoorDash refusing an address is remembered, so checkout doesn't keep asking. */
    private const REFUSAL_MINUTES = 1;

    /** A booking with no answer after this long, that DoorDash doesn't have, is given up on. */
    private const BOOKING_MINUTES = 5;

    public function __construct(
        private readonly DoorDashGateway $doordash,
        private readonly OrderService $orders,
    ) {}

    /**
     * What DoorDash charges, in cents, to deliver an order of this value from the restaurant to
     * the address. Nothing is booked. DoorDash's reason for any refusal is logged: the customer
     * only hears that DoorDash can't deliver there.
     *
     * @throws DoorDashRefused when DoorDash won't price it
     * @throws CourierUnavailable
     */
    public function price(Restaurant $restaurant, DeliveryAddress $address, int $orderValueCents): int
    {
        $key = "doordash-price:{$restaurant->id}:".sha1("{$address->oneLine()}|{$orderValueCents}");
        $known = Cache::get($key);

        if (is_array($known) && is_int($known['fee'] ?? null)) {
            return $known['fee'];
        }

        if (is_array($known) && is_string($known['refused'] ?? null)) {
            throw new DoorDashRefused(422, $known['refused']);
        }

        try {
            $quote = $this->doordash->quote($restaurant, $this->quoteRequest($restaurant, $address->oneLine(), $orderValueCents));
        } catch (DoorDashRefused $refused) {
            Log::warning('DoorDash refused to price a delivery.', [
                'restaurant' => $restaurant->slug,
                'address' => $address->oneLine(),
                'status' => $refused->status,
                'reason' => $refused->getMessage(),
            ]);

            // An address DoorDash can't serve is remembered for a moment. A refusal about the
            // restaurant's details isn't, so it can be put right straight away.
            if ($refused->isAboutDropoff()) {
                Cache::put($key, ['refused' => $refused->getMessage()], now()->addMinutes(self::REFUSAL_MINUTES));
            }

            throw $refused;
        }

        if ($quote->feeCents === null) {
            throw CourierUnavailable::because(new RuntimeException('DoorDash quoted a delivery without a fee.'));
        }

        Cache::put($key, ['fee' => $quote->feeCents], now()->addMinutes(self::PRICE_MINUTES));

        return $quote->feeCents;
    }

    /**
     * The quote that prices a delivery from the restaurant to an address, as DoorDash gets it
     * (also for `doordash:quote`, which shows DoorDash's answer).
     *
     * @return array<string, mixed>
     */
    public function quoteRequest(Restaurant $restaurant, string $dropoffAddress, int $orderValueCents): array
    {
        return [
            // Every quote needs an ID of its own; this one is never booked.
            'external_delivery_id' => 'price-'.Str::lower((string) Str::ulid()),
            ...$this->pickup($restaurant),
            'dropoff_address' => $dropoffAddress,
            'order_value' => $orderValueCents,
        ];
    }

    /**
     * Books a driver for an accepted delivery order, unless one is already booked: a quote,
     * accepted straight away. A booking whose answer never came back is looked up first, so the
     * order never gets two drivers. When DoorDash says no, the order shows the kitchen why.
     *
     * @throws CourierUnavailable when DoorDash can't be reached; nothing new was booked
     */
    public function book(Order $order): Order
    {
        $lock = Cache::lock("courier:{$order->id}", 60);

        if (! $lock->get()) {
            // The kitchen and the queue are booking it at the same moment: one is enough.
            return $order->refresh();
        }

        try {
            $order = $order->refresh()->load(['restaurant', 'items.modifiers', 'deliveryZone']);

            if (! $order->courierCanBeBooked()) {
                return $order;
            }

            if ($order->courier_delivery_id !== null && $order->courier_status === null) {
                $earlier = $this->findBooking($order);

                if ($earlier !== null) {
                    return $this->booked($order, $earlier);
                }
            }

            $attempt = $order->courier_attempts + 1;
            $id = $attempt === 1 ? $order->public_id : "{$order->public_id}-{$attempt}";

            // Saved before DoorDash is asked, so a booking whose answer is lost can be found again.
            $order->forceFill([
                'courier_attempts' => $attempt,
                'courier_delivery_id' => $id,
                'courier_status' => null,
                'courier_driver_name' => null,
                'courier_tracking_url' => null,
                'courier_support_reference' => null,
                'courier_fee_cents' => null,
                'courier_problem' => null,
                'courier_updated_at' => now(),
            ])->save();

            try {
                $this->doordash->quote($order->restaurant, $this->bookingRequest($order, $id, CarbonImmutable::now()));
                $delivery = $this->doordash->acceptQuote($order->restaurant, $id);
            } catch (DoorDashRefused $refused) {
                return $this->bookingFailed($order, "DoorDash couldn’t book a driver ({$refused->getMessage()}). Book again, or deliver it yourselves.");
            }

            return $this->booked($order, $delivery);
        } finally {
            $lock->release();
        }
    }

    /** No driver could be booked: the kitchen sees why, and can book again. */
    public function bookingFailed(Order $order, string $problem): Order
    {
        $order->forceFill([
            'courier_status' => CourierStatus::Failed,
            'courier_problem' => Str::limit($problem, 490),
            'courier_updated_at' => now(),
        ])->save();

        $this->announce($order);

        return $order;
    }

    /**
     * Calls off the order's driver, now the order itself is cancelled. DoorDash allows it until
     * a driver is assigned; after that the restaurant has to ask DoorDash's support, and the
     * order says so.
     *
     * @throws CourierUnavailable (the queue tries again)
     */
    public function cancel(Order $order): Order
    {
        $order = $order->refresh()->load('restaurant');

        if ($order->courier_delivery_id === null || ($order->courier_status?->isFinal() ?? false)) {
            return $order;
        }

        try {
            $this->doordash->cancel($order->restaurant, $order->courier_delivery_id);
        } catch (DoorDashRefused $refused) {
            if (! $refused->isUnknown()) {
                Log::warning('DoorDash wouldn’t cancel a delivery; ask DoorDash support to.', [
                    'order' => $order->public_id,
                    'delivery' => $order->courier_delivery_id,
                    'support_reference' => $order->courier_support_reference,
                    'reason' => $refused->getMessage(),
                ]);

                $order->forceFill([
                    'courier_problem' => Str::limit(trim("DoorDash wouldn’t cancel the driver ({$refused->getMessage()}). Call DoorDash support"
                        .($order->courier_support_reference === null ? '.' : ", reference {$order->courier_support_reference}.")), 490),
                ])->save();
                $this->announce($order);

                return $order;
            }
            // DoorDash never had the booking: there's nothing to call off.
        }

        $order->forceFill(['courier_status' => CourierStatus::Cancelled, 'courier_problem' => null, 'courier_updated_at' => now()])->save();
        $this->announce($order);

        return $order;
    }

    /**
     * A webhook from the restaurant's DoorDash account. Deliveries the platform didn't book
     * (made in DoorDash's own portal, say) and events it doesn't act on are ignored.
     *
     * @param  array<string, mixed>  $event
     */
    public function receive(Restaurant $restaurant, array $event): void
    {
        $name = $event['event_name'] ?? null;
        $status = is_string($name) ? CourierStatus::fromDoorDashEvent($name) : null;
        $delivery = DoorDashDelivery::fromArray($event);

        if ($status === null || $delivery->externalDeliveryId === '') {
            return;
        }

        $order = $restaurant->orders()->where('courier_delivery_id', $delivery->externalDeliveryId)->first();

        if ($order !== null) {
            $this->update($order, $status, $delivery);
        }
    }

    /**
     * Asks DoorDash where the order's delivery is up to, for news its webhook didn't bring. A
     * booking that got no answer, and that DoorDash doesn't have after a few minutes, is given
     * up on, so the kitchen can book again.
     *
     * @throws CourierUnavailable
     */
    public function refresh(Order $order): Order
    {
        if ($order->courier_delivery_id === null) {
            return $order;
        }

        try {
            $delivery = $this->doordash->delivery($order->restaurant, $order->courier_delivery_id);
        } catch (DoorDashRefused) {
            $delivery = null;
        }

        $status = $delivery === null ? null : CourierStatus::fromDoorDashStatus($delivery->status);

        if ($delivery !== null && $status !== null) {
            return $this->update($order, $status, $delivery);
        }

        $stale = $order->courier_updated_at === null || $order->courier_updated_at->lt(now()->subMinutes(self::BOOKING_MINUTES));

        return $order->courier_status === null && $stale
            ? $this->bookingFailed($order, 'DoorDash didn’t book a driver. Book again, or deliver it yourselves.')
            : $order;
    }

    /**
     * Applies DoorDash's news about a delivery. Only the order's current booking counts, and
     * news never moves a delivery back a stage. The order follows: out for delivery once the
     * driver has it, completed once it's delivered.
     */
    public function update(Order $order, CourierStatus $status, DoorDashDelivery $delivery): Order
    {
        return DB::transaction(function () use ($order, $status, $delivery): Order {
            $order = Order::query()->with('restaurant')->lockForUpdate()->findOrFail($order->id);

            if ($order->courier_delivery_id !== $delivery->externalDeliveryId) {
                // About an earlier booking.
                return $order;
            }

            $current = $order->courier_status;
            $moves = $current === null || (! $current->isFinal() && $status !== $current && $status->stage() >= $current->stage());

            $order->forceFill(array_filter([
                'courier_driver_name' => $delivery->dasherName,
                'courier_tracking_url' => $delivery->trackingUrl,
                'courier_support_reference' => $delivery->supportReference,
                'courier_fee_cents' => $delivery->feeCents,
            ], fn (mixed $value): bool => $value !== null));

            if ($moves) {
                $order->forceFill([
                    'courier_status' => $status,
                    // A problem only matters while the order's still going.
                    'courier_problem' => $order->status->isFinal() ? null : self::problem($status, $delivery),
                    'courier_updated_at' => now(),
                    // Back to finding a driver: the last one dropped out.
                    ...($status === CourierStatus::Booked ? ['courier_driver_name' => null] : []),
                ]);
            }

            if (! $order->isDirty()) {
                return $order;
            }

            $order->save();
            $before = $order->status;

            $order = match ($moves ? $status : null) {
                CourierStatus::PickedUp, CourierStatus::AtDropoff => $this->orders->followCourier($order, OrderStatus::OutForDelivery, 'The DoorDash driver picked it up.'),
                CourierStatus::Delivered => $this->orders->followCourier($order, OrderStatus::Completed, 'Delivered by DoorDash.'),
                default => $order,
            };

            // A status change tells everyone itself; otherwise the screens are told to look again.
            if ($order->status === $before) {
                DB::afterCommit(fn () => $this->announce($order));
            }

            return $order;
        });
    }

    /**
     * The order's last booking, if DoorDash got it: booked, or a quote still in time to accept.
     */
    private function findBooking(Order $order): ?DoorDashDelivery
    {
        $id = (string) $order->courier_delivery_id;

        try {
            $delivery = $this->doordash->delivery($order->restaurant, $id);

            return $delivery->status === 'quote' ? $this->doordash->acceptQuote($order->restaurant, $id) : $delivery;
        } catch (DoorDashRefused) {
            // Never got it, or the quote expired: book afresh.
            return null;
        }
    }

    private function booked(Order $order, DoorDashDelivery $delivery): Order
    {
        // DoorDash's webhook can bring news of the booking before its own answer arrives.
        $order->refresh();

        $order->forceFill([
            'courier_status' => $order->courier_status ?? CourierStatus::fromDoorDashStatus($delivery->status) ?? CourierStatus::Booked,
            'courier_driver_name' => $delivery->dasherName,
            'courier_tracking_url' => $delivery->trackingUrl,
            'courier_support_reference' => $delivery->supportReference,
            'courier_fee_cents' => $delivery->feeCents,
            'courier_problem' => null,
            'courier_updated_at' => now(),
        ])->save();

        $this->announce($order);

        return $order;
    }

    /**
     * The booking DoorDash gets for an order: the restaurant, the customer and their address,
     * what's in the bag, and when to come.
     *
     * @return array<string, mixed>
     */
    private function bookingRequest(Order $order, string $id, CarbonImmutable $now): array
    {
        [$givenName, $familyName] = self::names($order->customer_name);
        $address = new DeliveryAddress($order->delivery_line1, $order->delivery_line2, $order->delivery_suburb, $order->delivery_state, $order->delivery_postcode);

        return array_filter([
            'external_delivery_id' => $id,
            ...$this->pickup($order->restaurant),
            'pickup_instructions' => self::sentences(["Order {$order->display_number} for {$givenName}", $order->restaurant->address['line2'] ?? null]),
            'dropoff_address' => $address->oneLine(),
            'dropoff_phone_number' => PhoneNumber::e164($order->customer_phone),
            'dropoff_contact_given_name' => $givenName,
            'dropoff_contact_family_name' => $familyName,
            'dropoff_instructions' => self::sentences([$order->delivery_line2, $order->delivery_instructions]),
            // DoorDash texts the customer the driver's progress and its live map.
            'dropoff_contact_send_notifications' => true,
            // The food's value, which DoorDash refunds against if the delivery fails.
            'order_value' => max(0, $order->subtotal_cents - $order->discount_cents),
            'items' => $order->items->map(fn (OrderItem $item): array => array_filter([
                'name' => $item->name,
                'quantity' => $item->quantity,
                'description' => $item->modifiers->pluck('name')->implode(', '),
                'price' => $item->unit_price_cents,
            ], fn (mixed $value): bool => $value !== ''))->values()->all(),
            ...$this->timing($order, $now),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Where the driver collects the order: the restaurant, by name, and the number its drivers
     * call (DoorDash requires it, with the country code).
     *
     * @return array<string, string>
     */
    private function pickup(Restaurant $restaurant): array
    {
        $address = $restaurant->address ?? [];

        return array_filter([
            'pickup_address' => (new DeliveryAddress($address['line1'] ?? null, null, $address['suburb'] ?? null, $address['state'] ?? null, $address['postcode'] ?? null))->oneLine(),
            'pickup_business_name' => $restaurant->name,
            'pickup_phone_number' => PhoneNumber::e164($restaurant->phone),
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * When the driver should come. For a time chosen more than an hour ahead, the time it should
     * arrive (DoorDash plans the pickup); otherwise when it's ready: the kitchen's estimate, or
     * for a time chosen within the hour, that time less the zone's delivery time. Less than 5
     * minutes away, as soon as possible.
     *
     * @return array<string, string>
     */
    private function timing(Order $order, CarbonImmutable $now): array
    {
        $wanted = $order->scheduled_for?->toImmutable();

        if ($wanted !== null && $wanted->greaterThan($now->addHour())) {
            return ['dropoff_time' => $wanted->utc()->toIso8601ZuluString()];
        }

        $pickupAt = $wanted !== null
            ? $wanted->subMinutes($order->deliveryZone->estimated_minutes ?? 30)
            : $order->estimated_ready_at?->toImmutable();

        return $pickupAt !== null && $pickupAt->greaterThan($now->addMinutes(5))
            ? ['pickup_time' => $pickupAt->utc()->toIso8601ZuluString()]
            : [];
    }

    /** What the kitchen should know when a delivery goes wrong. */
    private static function problem(CourierStatus $status, DoorDashDelivery $delivery): ?string
    {
        $reason = $delivery->cancellationReason === null ? '' : ' ('.str_replace('_', ' ', $delivery->cancellationReason).')';

        return match ($status) {
            CourierStatus::Cancelled => "DoorDash cancelled the delivery{$reason}. Book another driver, or deliver it yourselves.",
            CourierStatus::Returning => 'The driver couldn’t deliver it and is bringing it back.',
            CourierStatus::Returned => 'The driver brought it back.',
            default => null,
        };
    }

    /**
     * "Sam Taylor" → ["Sam", "Taylor"]; a single name has no family name.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function names(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [$parts[0] ?? $name, $parts[1] ?? null];
    }

    /**
     * Notes for the driver, as sentences: ["Apartment 1204", "Buzz 1204"] → "Apartment 1204. Buzz 1204."
     *
     * @param  list<string|null>  $parts
     */
    private static function sentences(array $parts): ?string
    {
        $parts = array_values(array_filter(array_map(fn (?string $part): string => rtrim(trim((string) $part), '.'), $parts)));

        return $parts === [] ? null : implode('. ', $parts).'.';
    }

    /** Screens showing the order fetch it again (the broadcast carries no details). */
    private function announce(Order $order): void
    {
        try {
            OrderUpdated::dispatch($order);
        } catch (BroadcastException $exception) {
            report($exception);
        }
    }
}
