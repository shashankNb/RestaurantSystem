<?php

use App\Http\Controllers\SquareConnectController;
use Illuminate\Support\Facades\Route;

// The API host serves the REST API (/api/v1) and the owner back office (/admin).
Route::redirect('/', '/admin');

// "Connect with Square" from the back office (Restaurant settings → Payments). The callback is
// the redirect URL registered with the platform's Square application.
Route::get('square/connect/{restaurant:slug}', [SquareConnectController::class, 'connect'])->name('square.connect');
Route::get('square/oauth/callback', [SquareConnectController::class, 'callback'])->name('square.callback');
