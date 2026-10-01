<?php

namespace Database\Factories;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An unpaid ASAP pickup order by default. The lifecycle states build on each
 * other the way a real order moves through the kitchen.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    private static int $orderNumber = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(15, 80) * 100;

        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => null,
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Unpaid,
            'fulfilment_type' => FulfilmentType::Pickup,
            'scheduled_for' => null,
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->numerify('04## ### ###'),
            'customer_email' => fake()->safeEmail(),
            'subtotal_cents' => $subtotal,
            'delivery_fee_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => $subtotal,
            'gst_cents' => (int) round($subtotal / 11),
            'idempotency_key' => (string) Str::uuid(),
            'tracking_token' => Str::random(40),
        ];
    }

    public function delivery(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_line1' => fake()->numberBetween(1, 200).' Collins Street',
            'delivery_suburb' => 'Melbourne',
            'delivery_state' => 'VIC',
            'delivery_postcode' => '3000',
            'delivery_fee_cents' => 600,
            'total_cents' => $attributes['subtotal_cents'] - $attributes['discount_cents'] + 600,
            'gst_cents' => (int) round(($attributes['subtotal_cents'] - $attributes['discount_cents'] + 600) / 11),
        ]);
    }

    public function placed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Placed,
            'payment_status' => PaymentStatus::Paid,
            'stripe_payment_intent_id' => 'pi_test_'.Str::random(24),
            'placed_at' => now(),
            'business_date' => now('Australia/Melbourne')->toDateString(),
            'order_number' => ++self::$orderNumber,
        ]);
    }

    public function accepted(int $prepMinutes = 15): static
    {
        return $this->placed()->state(fn (): array => [
            'status' => OrderStatus::Accepted,
            'prep_minutes' => $prepMinutes,
            'accepted_at' => now(),
            'estimated_ready_at' => now()->addMinutes($prepMinutes),
        ]);
    }

    public function preparing(): static
    {
        return $this->accepted()->state(['status' => OrderStatus::Preparing]);
    }

    public function ready(): static
    {
        return $this->preparing()->state(fn (): array => [
            'status' => OrderStatus::Ready,
            'ready_at' => now(),
        ]);
    }

    public function outForDelivery(): static
    {
        return $this->delivery()->ready()->state(['status' => OrderStatus::OutForDelivery]);
    }

    public function completed(): static
    {
        return $this->ready()->state(fn (): array => [
            'status' => OrderStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Kitchen is too busy'): static
    {
        return $this->placed()->state(fn (): array => [
            'status' => OrderStatus::Rejected,
            'payment_status' => PaymentStatus::Refunded,
            'rejected_at' => now(),
            'refunded_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
