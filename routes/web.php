<?php

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', [EventController::class, 'dashboard'])->name('dashboard');

Route::get('/', function () {
    return redirect()->route('dashboard');
});
