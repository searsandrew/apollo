<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('company')->name('company.')->group(function () {
    Route::livewire('/', 'pages::company.index')->name('index');
    Route::livewire('/{company}', 'pages::company.show')->name('show')->middleware('can:view company');
    Route::livewire('/{company}/orders', 'pages::company.orders')->name('orders')->middleware('can:view order');
});
