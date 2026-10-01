<?php

use App\Console\Commands\AutoRejectOrders;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderCancelled;
use App\Models\Order;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakePaymentGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->menu->restaurant->update(['auto_reject_minutes' => 10]);
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);
    $this->payments = $payments;

    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

describe('unpaid checkouts', function () {
    it('cancels checkouts still unpaid after 30 minutes, and their PaymentIntents', function () {
        $stale = Order::factory()->for($this->menu->restaurant)->create(['stripe_payment_intent_id' => 'pi_stale', 'created_at' => now()->subMinutes(31)]);
        $recent = Order::factory()->for($this->menu->restaurant)->create(['created_at' => now()->subMinutes(20)]);
        $paid = Order::factory()->for($this->menu->restaurant)->placed()->create(['created_at' => now()->subHour()]);

        $this->artisan('orders:cancel-unpaid')->expectsOutput('Cancelled 1 unpaid order.')->assertSuccessful();

        expect($stale->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($stale->statusEvents()->latest('id')->first()?->note)->toBe('Payment wasn’t completed in time.')
            ->and($this->payments->cancelled)->toBe(['pi_stale'])
            ->and($this->payments->refunds)->toBe([])
            ->and($recent->refresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($paid->refresh()->status)->toBe(OrderStatus::Placed);
    });

    it('tells nobody about a checkout that was never paid', function () {
        Mail::fake();
        Order::factory()->for($this->menu->restaurant)->create(['created_at' => now()->subMinutes(31), 'push_token' => 'ExponentPushToken[x]']);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        Mail::assertNothingQueued();
        Http::assertNothingSent();
    });
});

describe('auto-reject', function () {
    it('rejects and refunds an order not accepted in time, and tells the customer', function () {
        Mail::fake();
        $order = Order::factory()->for($this->menu->restaurant)->placed()->create([
            'stripe_payment_intent_id' => 'pi_waiting',
            'placed_at' => now()->subMinutes(11),
            'push_token' => 'ExponentPushToken[customer]',
        ]);

        $this->artisan('orders:auto-reject')->expectsOutput('Rejected 1 order not accepted in time.')->assertSuccessful();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Rejected)
            ->and($order->rejection_reason)->toBe(AutoRejectOrders::REASON)
            ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
            ->and($this->payments->refunds)->toHaveCount(1)
            ->and($order->statusEvents()->latest('id')->first()?->user_id)->toBeNull();

        Mail::assertQueued(OrderCancelled::class, fn (OrderCancelled $mail) => $mail->hasTo($order->customer_email));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host')
            && $request[0]['to'] === 'ExponentPushToken[customer]'
            && $request[0]['body'] === 'It wasn’t confirmed in time. You’ll get a full refund.');
    });

    it('leaves orders that still have time, and ones already accepted', function () {
        $waiting = Order::factory()->for($this->menu->restaurant)->placed()->create(['placed_at' => now()->subMinutes(9)]);
        $accepted = Order::factory()->for($this->menu->restaurant)->accepted()->create(['placed_at' => now()->subMinutes(30)]);

        $this->artisan('orders:auto-reject')->expectsOutput('Rejected 0 orders not accepted in time.')->assertSuccessful();

        expect($waiting->refresh()->status)->toBe(OrderStatus::Placed)
            ->and($accepted->refresh()->status)->toBe(OrderStatus::Accepted);
    });

    it('starts the clock at opening time for orders placed while closed', function () {
        // Placed at noon for this evening; the restaurant opens at 5 pm.
        $order = Order::factory()->for($this->menu->restaurant)->placed()->create([
            'placed_at' => CarbonImmutable::parse('2026-10-05 12:00', 'Australia/Melbourne')->utc(),
            'scheduled_for' => CarbonImmutable::parse('2026-10-05 19:00', 'Australia/Melbourne')->utc(),
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 17:09', 'Australia/Melbourne'));
        $this->artisan('orders:auto-reject')->assertSuccessful();
        expect($order->refresh()->status)->toBe(OrderStatus::Placed);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 17:10', 'Australia/Melbourne'));
        $this->artisan('orders:auto-reject')->assertSuccessful();
        expect($order->refresh()->status)->toBe(OrderStatus::Rejected);
    });
});

it('runs both jobs every minute', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('orders:cancel-unpaid')
        ->expectsOutputToContain('orders:auto-reject')
        ->assertSuccessful();
});
