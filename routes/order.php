<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::livewire('orders', 'pages::order.index')->name('order.index')->middleware('can:view order');
Route::middleware('auth')->prefix('order')->name('order.')->group(function () {
    Route::post('/create', [OrderController::class, 'create'])->name('create')->middleware('can:create order');
    Route::livewire('/{order}', 'pages::order.show')->name('show')->middleware('can:view order');
});
