<?php

use App\Services\NetSuite\NetSuiteTransactionStatusMapper;

it('maps transaction status codes by transaction type', function (): void {
    $mapper = app(NetSuiteTransactionStatusMapper::class);

    expect($mapper->label('SalesOrd', 'B'))->toBe('Pending Fulfillment')
        ->and($mapper->label('CustInvc', 'B'))->toBe('Paid In Full')
        ->and($mapper->label('CustCred', 'B'))->toBe('Fully Applied')
        ->and($mapper->label('PurchOrd', 'B'))->toBe('Pending Receipt');
});

it('maps prefixed transaction statuses and leaves unknown values readable', function (): void {
    $mapper = app(NetSuiteTransactionStatusMapper::class);

    expect($mapper->label(null, 'SalesOrd:G'))->toBe('Billed')
        ->and($mapper->label('CustInvc', 'Already Human'))->toBe('Already Human')
        ->and($mapper->label('CustInvc', null))->toBe('-');
});

it('chooses badge colors from status meaning', function (): void {
    $mapper = app(NetSuiteTransactionStatusMapper::class);

    expect($mapper->color('SalesOrd', 'C'))->toBe('red')
        ->and($mapper->color('SalesOrd', 'E'))->toBe('blue')
        ->and($mapper->color('CustInvc', 'B'))->toBe('green')
        ->and($mapper->color('SalesOrd', 'H'))->toBe('zinc');
});
