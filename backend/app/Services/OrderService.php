<?php

namespace App\Services;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderUpdated;
use App\Jobs\RefundOrder;
use App\Jobs\SendOrderPushNotification;
use App\Mail\OrderCancelled;
use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentsUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The order lifecycle, and the only code that changes an order's status:
 *
 *   pending_payment → placed → accepted → preparing → ready → (out_for_delivery) → completed
 *
 * plus rejected (by the kitchen, from placed) and cancelled (from any unfinished state).
 * Delivery orders go out for delivery before they're completed; pickup orders never do.
 * Every change is recorded in order_status_events, and after it commits the customer is
 * told: a broadcast, a push notification and, where it matters, an email. Rejecting an
 * order, or cancelling one that's paid, refunds it in full.
 */
final class OrderService
{
    /**
     * @var array<string, list<OrderStatus>>
     */
    private const TRANSITIONS = [
        'pending_payment' => [OrderStatus::Placed, OrderStatus::Cancelled],
        'placed' => [OrderStatus::Accepted, OrderStatus::Rejected, OrderStatus::Cancelled],
        'accepted' => [OrderStatus::Preparing, OrderStatus::Cancelled],
        'preparing' => [OrderStatus::Ready, OrderStatus::Cancelled],
        'ready' => [OrderStatus::OutForDelivery, OrderStatus::Completed, OrderStatus::Cancelled],
        'out_for_delivery' => [OrderStatus::Completed, OrderStatus::Cancelled],
    ];

    public function __construct(
        private readonly PaymentGateway $payments,
        private readonly OpeningHoursService $hours,
    ) {}

    public function canTransition(Order $order, OrderStatus $to): bool
    {
        if (! in_array($to, self::TRANSITIONS[$order->status->value] ?? [], true)) {
            return false;
        }

        $delivery = $order->fulfilment_type === FulfilmentType::Delivery;

        return match (true) {
            $to === OrderStatus::OutForDelivery => $delivery,
            // A delivery order is completed once it has been out for delivery.
            $to === OrderStatus::Completed && $order->status === OrderStatus::Ready => ! $delivery,
            default => true,
        };
    }

    /**
     * Stripe confirmed the payment (webhook): the order is paid and goes to the kitchen. Safe
     * to call twice. A payment that arrives after the order was cancelled is refunded.
     */
    public function markPaid(Order $order, string $paymentIntentId, int $amountCents): Order
    {
        return DB::transaction(function () use ($order, $paymentIntentId, $amountCents): Order {
            // Locking the restaurant serialises order numbers for orders paid at the same moment.
            Restaurant::query()->whereKey($order->restaurant_id)->lockForUpdate()->first();
            $order = $this->lock($order);

            if ($order->payment_status === PaymentStatus::Paid || $order->payment_status === PaymentStatus::Refunded) {
                return $order;
            }

            if ($order->stripe_payment_intent_id !== $paymentIntentId || $amountCents !== $order->total_cents) {
                Log::critical('Payment does not match its order.', [
                    'order' => $order->public_id,
                    'payment_intent' => $paymentIntentId,
                    'amount' => $amountCents,
                ]);

                return $order;
            }

            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

            if ($order->status !== OrderStatus::PendingPayment) {
                // Paid after it was cancelled (the checkout expired): give the money back.
                $this->queueRefund($order);

                return $order;
            }

            $now = CarbonImmutable::now();
            [$businessDate, $number] = $this->nextOrderNumber($order, $now);

            if ($order->promo_code_id !== null) {
                PromoCode::query()->whereKey($order->promo_code_id)->increment('uses_count');
            }

            return $this->transition($order, OrderStatus::Placed, null, null, [
                'business_date' => $businessDate,
                'order_number' => $number,
                'placed_at' => $now,
            ]);
        });
    }

    /**
     * The kitchen takes the order and says how long it will take.
     */
    public function accept(Order $order, int $prepMinutes, User $by): Order
    {
        return DB::transaction(function () use ($order, $prepMinutes, $by): Order {
            $order = $this->lock($order);
            $now = CarbonImmutable::now();
            // A scheduled order is ready when it was promised for; ASAP, after the prep time.
            $readyAt = $order->scheduled_for !== null && $order->scheduled_for->greaterThan($now->addMinutes($prepMinutes))
                ? $order->scheduled_for
                : $now->addMinutes($prepMinutes);

            return $this->transition($order, OrderStatus::Accepted, $by, "Ready in {$prepMinutes} minutes.", [
                'prep_minutes' => $prepMinutes,
                'accepted_at' => $now,
                'estimated_ready_at' => $readyAt,
            ]);
        });
    }

    /**
     * The kitchen can't make the order (or didn't answer in time, when $by is null). The
     * customer is refunded in full and told why.
     */
    public function reject(Order $order, string $reason, ?User $by): Order
    {
        return DB::transaction(function () use ($order, $reason, $by): Order {
            $order = $this->transition($this->lock($order), OrderStatus::Rejected, $by, $reason, [
                'rejection_reason' => $reason,
                'rejected_at' => CarbonImmutable::now(),
            ]);
            $this->queueRefund($order);

            return $order;
        });
    }

    /**
     * Moves an order along: preparing, ready, out for delivery, completed or cancelled.
     */
    public function advance(Order $order, OrderStatus $to, User $by, ?string $note = null): Order
    {
        if ($to === OrderStatus::Cancelled) {
            return $this->cancel($order, $by, $note ?? 'Cancelled by the restaurant.');
        }

        if (in_array($to, [OrderStatus::Placed, OrderStatus::Accepted, OrderStatus::Rejected, OrderStatus::PendingPayment], true)) {
            throw InvalidOrderTransition::between($order->status, $to);
        }

        return DB::transaction(fn (): Order => $this->transition($this->lock($order), $to, $by, $note, match ($to) {
            OrderStatus::Ready => ['ready_at' => CarbonImmutable::now()],
            OrderStatus::Completed => ['completed_at' => CarbonImmutable::now()],
            default => [],
        }));
    }

    /**
     * Cancels an unfinished order, refunding it if it was paid.
     */
    public function cancel(Order $order, ?User $by, string $note): Order
    {
        return DB::transaction(function () use ($order, $by, $note): Order {
            $order = $this->transition($this->lock($order), OrderStatus::Cancelled, $by, $note, [
                'cancelled_at' => CarbonImmutable::now(),
            ]);
            $this->queueRefund($order);

            return $order;
        });
    }

    /**
     * An unpaid checkout that timed out: cancel it, and stop its PaymentIntent from being
     * paid later. (If it's paid anyway, markPaid refunds it.)
     */
    public function expireUnpaid(Order $order): Order
    {
        $order = $this->cancel($order, null, 'Payment wasn’t completed in time.');

        if ($order->stripe_payment_intent_id !== null) {
            try {
                $this->payments->cancelPaymentIntent($order->stripe_payment_intent_id);
            } catch (PaymentsUnavailable $exception) {
                report($exception);
            }
        }

        return $order;
    }

    /**
     * Refunds a paid order in full from the back office. An unfinished order is cancelled
     * too; a completed one keeps its status.
     */
    public function refund(Order $order, User $by, string $reason): Order
    {
        if (! $order->status->isFinal()) {
            return $this->cancel($order, $by, $reason);
        }

        return DB::transaction(function () use ($order): Order {
            $order = $this->lock($order);
            $this->queueRefund($order);

            return $order;
        });
    }

    /**
     * When an order not yet accepted is rejected automatically: the restaurant's
     * auto_reject_minutes after it was placed, or after the restaurant opened if it was
     * placed (for later) while closed.
     */
    public function acceptDeadline(Order $order): CarbonImmutable
    {
        $placedAt = CarbonImmutable::parse($order->placed_at ?? $order->created_at);
        $status = $this->hours->status($order->restaurant, $placedAt);
        $clockStarts = $status->isOpen ? $placedAt : ($status->nextOpeningAt ?? $placedAt);

        return $clockStarts->addMinutes($order->restaurant->auto_reject_minutes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Order $order, OrderStatus $to, ?User $by, ?string $note, array $attributes = []): Order
    {
        if (! $this->canTransition($order, $to)) {
            throw InvalidOrderTransition::between($order->status, $to);
        }

        $from = $order->status;
        $order->forceFill([...$attributes, 'status' => $to])->save();
        $order->statusEvents()->create([
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $by?->id,
            'note' => $note,
        ]);

        DB::afterCommit(fn () => $this->announce($order->fresh() ?? $order));

        return $order;
    }

    /**
     * Tells everyone about the order's new status: staff and the customer over Reverb, the
     * customer by push notification and, when it's placed or called off after payment, email.
     */
    private function announce(Order $order): void
    {
        try {
            OrderUpdated::dispatch($order);
        } catch (BroadcastException $exception) {
            // Live updates are a convenience: the change is saved and the screens poll.
            report($exception);
        }

        if ($order->payment_status === PaymentStatus::Unpaid || $order->payment_status === PaymentStatus::Failed) {
            return;
        }

        SendOrderPushNotification::dispatch($order->id, $order->status);

        if ($order->customer_email === null) {
            return;
        }

        if ($order->status === OrderStatus::Placed) {
            Mail::to($order->customer_email)->queue(new OrderPlaced($order));
        } elseif ($order->status === OrderStatus::Rejected || $order->status === OrderStatus::Cancelled) {
            Mail::to($order->customer_email)->queue(new OrderCancelled($order));
        }
    }

    private function queueRefund(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            RefundOrder::dispatch($order->id)->afterCommit();
        }
    }

    private function lock(Order $order): Order
    {
        return Order::query()->with('restaurant')->lockForUpdate()->findOrFail($order->id);
    }

    /**
     * The next daily number for the restaurant's business day. The day starts at
     * config('ordering.business_day_starts_at_hour') local time, not midnight, so a late
     * shift keeps counting. Call inside the transaction that locked the restaurant row.
     *
     * @return array{0: string, 1: int}
     */
    private function nextOrderNumber(Order $order, CarbonImmutable $now): array
    {
        $startsAt = (int) config('ordering.business_day_starts_at_hour', 4);
        $businessDate = $now->setTimezone($order->restaurant->timezone)->subHours($startsAt)->toDateString();
        $last = Order::query()
            ->where('restaurant_id', $order->restaurant_id)
            ->where('business_date', $businessDate)
            ->max('order_number');

        return [$businessDate, (is_numeric($last) ? (int) $last : 0) + 1];
    }
}
