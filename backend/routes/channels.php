<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (Reverb)
|--------------------------------------------------------------------------
|
| private-restaurant.{id}.orders: new and updated orders, for the restaurant's staff.
| order.{public_id} (public): one order's status and times, for the customer's tracking
| screen. Neither carries personal details; see App\Events\OrderUpdated.
|
| Apps authorise private channels at POST /api/v1/broadcasting/auth with their bearer token.
|
*/

Broadcast::channel('restaurant.{restaurant}.orders', fn (User $user, int $restaurant): bool => $user->worksAt($restaurant));
