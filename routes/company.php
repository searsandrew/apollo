<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('company')->name('company.')->group(function () {
    Route::livewire('/', 'pages::company.index')->name('index');
    Route::livewire('/{company}', 'pages::company.show')->name('show')->middleware('can:view company');
    Route::livewire('/{company}/purchase-orders', 'pages::company.purchase-orders')->name('purchase-orders')->middleware('can:create order');
    Route::livewire('/{company}/sales-orders', 'pages::company.sales-orders.index')->name('sales-orders.index')->middleware('can:view order');
    Route::livewire('/{company}/sales-orders/{transaction}', 'pages::company.sales-orders.show')->name('sales-orders.show')->middleware('can:view order');
    Route::livewire('/{company}/invoices', 'pages::company.invoices')->name('invoices.index')->middleware('can:view invoice');
    Route::livewire('/{company}/invoices/{transaction}', 'pages::company.invoices.show')->name('invoices.show')->middleware('can:view invoice');
    Route::livewire('/{company}/credit-memos/{transaction}', 'pages::company.credit-memos.show')->name('credit-memos.show')->middleware('can:view invoice');
});
