<?php

namespace App\Services;

use App\Data\Cart;
use App\Data\Checkout;
use App\Data\Customer;
use App\Data\Quote;
use App\Data\QuoteLine;
use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntent;
use App\Payments\PaymentsUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a cart into an order waiting for payment, with a Stripe PaymentIntent for the
 * server's total.
 *
 * Every request carries an Idempotency-Key. Sending the same request again (a retry after
 * a timeout, a double tap) returns the original order and PaymentIntent instead of a new
 * one; the same key with a different request is refused. Stripe gets its own idempotency
 * key per order, so a retry never creates a second PaymentIntent either.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly PaymentGateway $payments,
    ) {}

    /**
     * @throws CheckoutException
     * @throws PaymentsUnavailable
     */
    public function checkout(
        Restaurant $restaurant,
        Cart $cart,
        Customer $customer,
        ?User $user,
        string $idempotencyKey,
        string $fingerprint,
        CarbonImmutable $now,
    ): Checkout {
        // Keys are scoped to the restaurant so two restaurants' apps can't collide.
        $key = "{$restaurant->id}:{$idempotencyKey}";
        $existing = Order::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $this->resume($existing, $fingerprint);
        }

        // Paid into the restaurant's own Stripe account: until its keys are in, no order is
        // started that couldn't be paid for.
        if (! $restaurant->acceptsPayments()) {
            throw PaymentsUnavailable::notConfigured();
        }

        $quote = $this->pricing->quote($restaurant, $cart, $now);

        if (! $quote->canPlaceOrder()) {
            throw CheckoutException::notOrderable($quote);
        }

        try {
            $order = $this->createOrder($restaurant, $quote, $customer, $user, $key, $fingerprint);
        } catch (UniqueConstraintViolationException) {
            // Another request with the same key got there first.
            return $this->resume(Order::query()->where('idempotency_key', $key)->firstOrFail(), $fingerprint);
        }

        return new Checkout($order, $this->attachPaymentIntent($order), created: true);
    }

    private function resume(Order $order, string $fingerprint): Checkout
    {
        if (! hash_equals((string) $order->request_fingerprint, $fingerprint)) {
            throw CheckoutException::keyReused();
        }

        $paymentIntent = $order->stripe_payment_intent_id === null
            ? $this->attachPaymentIntent($order)
            : $this->payments->retrievePaymentIntent($order->loadMissing('restaurant'));

        return new Checkout($order, $paymentIntent, created: false);
    }

    /**
     * Creates the order's PaymentIntent, unless another request for the same order is doing
     * it right now. If Stripe fails, the order stays unpaid and a retry picks up from here.
     */
    private function attachPaymentIntent(Order $order): PaymentIntent
    {
        $lock = Cache::lock("checkout:{$order->id}", 30);

        if (! $lock->get()) {
            throw CheckoutException::inProgress();
        }

        try {
            $order->refresh();

            if ($order->stripe_payment_intent_id !== null) {
                return $this->payments->retrievePaymentIntent($order->loadMissing('restaurant'));
            }

            $paymentIntent = $this->payments->createPaymentIntent($order->loadMissing('restaurant'), "order-{$order->public_id}");
            $order->forceFill(['stripe_payment_intent_id' => $paymentIntent->id])->save();

            return $paymentIntent;
        } finally {
            $lock->release();
        }
    }

    private function createOrder(Restaurant $restaurant, Quote $quote, Customer $customer, ?User $user, string $key, string $fingerprint): Order
    {
        return DB::transaction(function () use ($restaurant, $quote, $customer, $user, $key, $fingerprint): Order {
            $delivery = $quote->cart->fulfilmentType === FulfilmentType::Delivery;

            $order = $restaurant->orders()->create([
                'user_id' => $user?->id,
                'fulfilment_type' => $quote->cart->fulfilmentType,
                'scheduled_for' => $quote->cart->scheduledFor,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_email' => $customer->email,
                'delivery_zone_id' => $delivery ? $quote->deliveryZone?->id : null,
                'delivery_line1' => $delivery ? $customer->deliveryLine1 : null,
                'delivery_line2' => $delivery ? $customer->deliveryLine2 : null,
                'delivery_suburb' => $delivery ? $customer->deliverySuburb : null,
                'delivery_state' => $delivery ? $customer->deliveryState : null,
                'delivery_postcode' => $delivery ? $quote->cart->postcode : null,
                'delivery_instructions' => $delivery ? $customer->deliveryInstructions : null,
                'dining_table_id' => $quote->table?->id,
                'table_label' => $quote->table?->label,
                'subtotal_cents' => $quote->subtotalCents,
                'delivery_fee_cents' => $quote->deliveryFeeCents,
                'discount_cents' => $quote->discountCents,
                'total_cents' => $quote->totalCents,
                'gst_cents' => $quote->gstCents,
                'promo_code_id' => $quote->promoCode?->id,
                'promo_code' => $quote->promoCode?->code,
                'notes' => $customer->notes,
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'tracking_token' => Str::random(40),
                'push_token' => $customer->pushToken,
            ]);

            foreach ($quote->lines as $line) {
                $this->createItem($order, $line);
            }

            $order->statusEvents()->create([
                'from_status' => null,
                'to_status' => OrderStatus::PendingPayment,
                'user_id' => $user?->id,
            ]);

            // Status and payment status come from the column defaults: pending_payment, unpaid.
            return $order->refresh();
        });
    }

    private function createItem(Order $order, QuoteLine $line): void
    {
        $item = $order->items()->create([
            'menu_item_id' => $line->item?->id,
            'name' => (string) $line->item?->name,
            'unit_price_cents' => $line->unitPriceCents,
            'quantity' => $line->cartItem->quantity,
            'line_total_cents' => $line->lineTotalCents,
            'notes' => $line->cartItem->notes,
        ]);

        foreach ($line->options as $option) {
            /** @var ModifierOption $option */
            $item->modifiers()->create([
                'modifier_option_id' => $option->id,
                'group_name' => (string) $option->group?->name,
                'name' => $option->name,
                'price_delta_cents' => $option->price_delta_cents,
            ]);
        }
    }
}
