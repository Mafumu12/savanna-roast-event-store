<?php

use App\Http\Controllers\EventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Default Sanctum route from `php artisan install:api` — kept as-is,
// not used by this project but harmless to leave in place.
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// GTM's server-side "HTTP Request" tag posts here on every add_to_cart,
// purchase, and view_item event — alongside (not instead of) the
// existing GA4 and TikTok destinations.
Route::post('/events', [EventController::class, 'store']);
