<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('order')->name('order.')->group(function () {
    Route::livewire('/', 'pages::order.index')->name('index')->middleware('can:view order');
    Route::livewire('/{order}', 'pages::order.show')->name('show')->middleware('can:view order');
});
