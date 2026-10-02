<?php

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Events\OrderUpdated;
use App\Mail\OrderCancelled;
use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\PushToken;
use App\Models\User;
use App\Services\PushNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->menu->restaurant->update([
        'name' => 'Himalayan Momo House',
        'abn' => '12 345 678 901',
        'phone' => '03 5550 0142',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function orderWithItems(MomoMenu $menu, array $attributes = [], string $state = 'placed'): Order
{
    $order = Order::factory()->for($menu->restaurant)->{$state}()->create([
        'customer_name' => 'Sam Taylor',
        'customer_email' => 'sam@example.com',
        'customer_phone' => '0491 570 110',
        'subtotal_cents' => 3980,
        'discount_cents' => 398,
        'delivery_fee_cents' => 600,
        'total_cents' => 4182,
        'gst_cents' => 380,
        'promo_code' => 'MOMO10',
        'order_number' => 42,
        ...$attributes,
    ]);
    $item = $order->items()->create([
        'menu_item_id' => $menu->steamed->id, 'name' => 'Steamed momo', 'unit_price_cents' => 1990, 'quantity' => 2, 'line_total_cents' => 3980,
    ]);
    $item->modifiers()->create(['modifier_option_id' => $menu->pork->id, 'group_name' => 'Choose filling', 'name' => 'Pork', 'price_delta_cents' => 100]);

    return $order;
}

describe('broadcasts', function () {
    it('saves the change even when live updates are down', function () {
        Exceptions::fake();
        Broadcast::extend('down', fn () => new class extends Broadcaster
        {
            public function auth($request): mixed
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result): mixed
            {
                return null;
            }

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Reverb is down.');
            }
        });
        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
        $staff = User::factory()->staffOf($this->menu->restaurant)->create();
        $order = orderWithItems($this->menu);

        $this->actingAs($staff)
            ->postJson("/api/v1/staff/orders/{$order->public_id}/accept", ['prep_minutes' => 20])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        expect($order->fresh()?->status)->toBe(OrderStatus::Accepted);
        Exceptions::assertReported(BroadcastException::class);
    });

    it('goes to the staff channel and the order’s own channel', function () {
        $order = orderWithItems($this->menu);
        $event = new OrderUpdated($order);

        expect(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))->toBe([
            "private-restaurant.{$this->menu->restaurant->id}.orders",
            "order.{$order->public_id}",
        ])
            ->and($event->broadcastAs())->toBe('order.updated');
    });

    it('carries status and times only, never personal details', function () {
        $order = orderWithItems($this->menu, ['fulfilment_type' => FulfilmentType::Delivery, 'delivery_line1' => '12 Southbank Boulevard', 'notes' => 'Ring twice']);

        $payload = (new OrderUpdated($order))->broadcastWith();

        expect(array_keys($payload))->toBe([
            'public_id', 'order_number', 'status', 'fulfilment_type', 'scheduled_for', 'placed_at', 'accepted_at',
            'estimated_ready_at', 'ready_at', 'completed_at', 'rejected_at', 'cancelled_at', 'updated_at',
        ])
            ->and($payload['order_number'])->toBe('042')
            ->and(json_encode($payload))->not->toContain('Sam')->not->toContain('Southbank')->not->toContain('0491')->not->toContain('Ring twice')->not->toContain('Steamed');
    });

    it('lets staff of the restaurant listen to its orders, and nobody else', function () {
        $staff = User::factory()->staffOf($this->menu->restaurant)->create();
        $staffElsewhere = User::factory()->staffOf(MomoMenu::create()->restaurant)->create();
        $channel = "private-restaurant.{$this->menu->restaurant->id}.orders";

        // Tests broadcast to the null driver; channels register on the driver that's active
        // when they load, so switch to Reverb and load them again.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'key', 'broadcasting.connections.reverb.secret' => 'secret', 'broadcasting.connections.reverb.app_id' => '1']);
        require base_path('routes/channels.php');

        $this->withToken($staff->createToken('Tablet')->plainTextToken)
            ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->app['auth']->forgetGuards();

        foreach ([User::factory()->create(), $staffElsewhere] as $outsider) {
            $this->app['auth']->forgetGuards();

            $this->withToken($outsider->createToken('Phone')->plainTextToken)
                ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
                ->assertForbidden();
        }
    });
});

describe('push notifications', function () {
    it('tells the customer’s devices, in plain words', function (OrderStatus $status, string $title, string $body, array $attributes) {
        // Datasets load before the clock is set, so times are worked out here.
        $attributes = array_map(fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value, $attributes);
        $customer = User::factory()->create();
        PushToken::factory()->for($this->menu->restaurant)->for($customer)->create(['expo_push_token' => 'ExponentPushToken[account]']);
        PushToken::factory()->for(MomoMenu::create()->restaurant)->for($customer)->create(['expo_push_token' => 'ExponentPushToken[other-app]']);
        $order = orderWithItems($this->menu, ['user_id' => $customer->id, 'push_token' => 'ExponentPushToken[guest]', ...$attributes]);
        $order->forceFill(['status' => $status])->save();

        app(PushNotifications::class)->sendOrderUpdate($order->refresh());

        Http::assertSent(function ($request) use ($title, $body) {
            $messages = $request->data();

            return count($messages) === 2
                && array_column($messages, 'to') === ['ExponentPushToken[guest]', 'ExponentPushToken[account]']
                && $messages[0]['title'] === $title
                && $messages[0]['body'] === $body
                && $messages[0]['channelId'] === 'default'
                && $messages[0]['data']['public_id'] !== null;
        });
    })->with([
        'placed' => [OrderStatus::Placed, 'Order 042 is in', 'Himalayan Momo House has your order. We’ll let you know when the kitchen accepts it.', []],
        'accepted' => [OrderStatus::Accepted, 'Order 042 accepted', 'It should be ready around 6:20 pm.', ['estimated_ready_at' => fn () => now()->addMinutes(20)]],
        'ready for pickup' => [OrderStatus::Ready, 'Order 042 is ready', 'Come and collect it from Himalayan Momo House.', []],
        'ready for delivery' => [OrderStatus::Ready, 'Order 042 is packed', 'It’s ready and waiting for the driver.', ['fulfilment_type' => FulfilmentType::Delivery]],
        'rejected' => [OrderStatus::Rejected, 'We can’t make order 042', 'We’ve run out of pork. You’ll get a full refund.', ['rejection_reason' => 'We’ve run out of pork']],
    ]);

    it('forgets devices Expo says are gone', function () {
        config(['services.expo.push_url' => 'https://push.test/send']);
        Http::fake(['push.test/*' => Http::response(['data' => [
            ['status' => 'error', 'message' => 'Not registered', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 'ticket-1'],
        ]])]);
        $customer = User::factory()->create();
        PushToken::factory()->for($this->menu->restaurant)->for($customer)->create(['expo_push_token' => 'ExponentPushToken[still-here]']);
        $order = orderWithItems($this->menu, ['user_id' => $customer->id, 'push_token' => 'ExponentPushToken[gone]']);

        app(PushNotifications::class)->sendOrderUpdate($order);

        expect($order->refresh()->push_token)->toBeNull()
            ->and(PushToken::query()->pluck('expo_push_token')->all())->toBe(['ExponentPushToken[still-here]']);
    });

    it('sends nothing when there’s no device to tell', function () {
        app(PushNotifications::class)->sendOrderUpdate(orderWithItems($this->menu));

        Http::assertNothingSent();
    });
});

describe('emails', function () {
    it('confirms the order as a tax invoice', function () {
        $order = orderWithItems($this->menu, ['fulfilment_type' => FulfilmentType::Delivery, 'delivery_suburb' => 'Southbank', 'delivery_postcode' => '3006']);

        $mail = new OrderPlaced($order);

        $mail->assertHasSubject('Order 042 from Himalayan Momo House')
            ->assertSeeInHtml('Thanks, Sam. Order 042 is in.', false)
            ->assertSeeInHtml('Delivery to Southbank 3006, as soon as possible', false)
            ->assertSeeInHtml('Tax invoice')
            ->assertSeeInHtml('ABN 12 345 678 901')
            ->assertSeeInHtml('Steamed momo (Pork)')
            ->assertSeeInHtml('$39.80')
            ->assertSeeInHtml('Discount (MOMO10)')
            ->assertSeeInHtml('$41.82')
            ->assertSeeInHtml('The total includes GST of $3.80.')
            ->assertSeeInHtml("https://order.example.test/order/{$order->public_id}?token={$order->tracking_token}", false);
    });

    it('carries the restaurant’s own name and website, not the platform’s', function () {
        config(['app.name' => 'Order Platform']);
        $this->menu->restaurant->update(['custom_domain' => 'order.momo.test']);
        $order = orderWithItems($this->menu, ['rejection_reason' => 'We’ve run out of pork'], 'rejected');

        foreach ([new OrderPlaced($order), new OrderCancelled($order)] as $mail) {
            $mail->assertSeeInHtml('href="https://order.momo.test"', false)
                ->assertSeeInHtml('© '.date('Y').' Himalayan Momo House.', false)
                ->assertDontSeeInHtml('Order Platform')
                ->assertDontSeeInText('Order Platform');
        }
    });

    it('explains a rejection and the refund', function () {
        $order = orderWithItems($this->menu, ['rejection_reason' => 'We’ve run out of pork'], 'rejected');

        (new OrderCancelled($order))
            ->assertHasSubject('Order 042 is cancelled and refunded')
            ->assertSeeInHtml('Himalayan Momo House couldn’t make your order: We’ve run out of pork.', false)
            ->assertSeeInHtml('We’ve refunded $41.82 to the card you paid with.', false);
    });
});
