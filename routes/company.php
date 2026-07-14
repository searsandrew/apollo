<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('company')->name('company.')->group(function () {
    Route::livewire('/', 'pages::company.index')->name('index');
    Route::livewire('/{company}', 'pages::company.show')->name('show')->middleware('can:view company');
    Route::livewire('/{company}/purchase-orders', 'pages::company.purchase-orders')->name('purchase-orders')->middleware('can:create order');
    Route::livewire('/{company}/sales-orders', 'pages::company.sales-orders')->name('sales-orders')->middleware('can:view order');
});
