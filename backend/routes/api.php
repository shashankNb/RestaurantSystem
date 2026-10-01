<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeliveryCheckController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\MyOrderController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\SlotsController;
use App\Http\Controllers\Api\Staff\AvailabilityController;
use App\Http\Controllers\Api\Staff\OrderController as StaffOrderController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefix /api/v1)
|--------------------------------------------------------------------------
|
| Every endpoint is documented in docs/API.md. Rate limiters are defined in
| AppServiceProvider.
|
*/

// Public
Route::prefix('restaurants/{restaurant}')->name('restaurants.')->group(function (): void {
    Route::get('/', [RestaurantController::class, 'show'])->name('show');
    Route::get('menu', [MenuController::class, 'show'])->name('menu');
    Route::get('slots', SlotsController::class)->name('slots');
    Route::post('delivery-check', DeliveryCheckController::class)
        ->middleware('throttle:delivery-check')
        ->name('delivery-check');
    Route::post('orders/quote', QuoteController::class)
        ->middleware('throttle:quote')
        ->name('orders.quote');
    // Requires an Idempotency-Key header; signed-in customers send their token too.
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('throttle:orders')
        ->name('orders.store');
});

// Order tracking: the customer, signed in or with the order's tracking token.
Route::get('orders/{publicId}', [OrderController::class, 'show'])->name('orders.show');

// Push notifications: signed in, or for one order with its tracking token.
Route::post('push-tokens', [PushTokenController::class, 'store'])
    ->middleware('throttle:push-tokens')
    ->name('push-tokens.store');

// Stripe (signature verified; each event processed once).
Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Accounts
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('register');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');
    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('logout');
});

// Signed-in customers (and staff)
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::delete('me', [MeController::class, 'destroy'])->name('me.destroy');
    Route::get('me/orders', [MyOrderController::class, 'index'])->name('me.orders');

    Route::get('me/addresses', [AddressController::class, 'index'])->name('me.addresses.index');
    Route::post('me/addresses', [AddressController::class, 'store'])->name('me.addresses.store');
    Route::patch('me/addresses/{address}', [AddressController::class, 'update'])->name('me.addresses.update');
    Route::delete('me/addresses/{address}', [AddressController::class, 'destroy'])->name('me.addresses.destroy');

    // Kitchen screens: staff and owners of the restaurant.
    Route::prefix('staff')->name('staff.')->group(function (): void {
        Route::get('orders', [StaffOrderController::class, 'index'])->name('orders.index');
        Route::post('orders/{order:public_id}/accept', [StaffOrderController::class, 'accept'])->name('orders.accept');
        Route::post('orders/{order:public_id}/reject', [StaffOrderController::class, 'reject'])->name('orders.reject');
        Route::post('orders/{order:public_id}/status', [StaffOrderController::class, 'status'])->name('orders.status');
        Route::patch('restaurant', [AvailabilityController::class, 'updateRestaurant'])->name('restaurant.update');
        Route::patch('menu-items/{menuItem}', [AvailabilityController::class, 'updateMenuItem'])->name('menu-items.update');
        Route::patch('modifier-options/{modifierOption}', [AvailabilityController::class, 'updateModifierOption'])->name('modifier-options.update');
    });
});
