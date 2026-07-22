<?php

use App\Jobs\SyncCompanySnapshotTransaction;
use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotDatabaseManager;
use App\Services\CompanySnapshots\CompanySnapshotTransactionDetailRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'company-snapshots.path' => storage_path('framework/testing/company-snapshots'),
    ]);

    File::deleteDirectory(storage_path('framework/testing/company-snapshots'));
});

it('renders a sales order detail from transaction snapshot data', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 16,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);
    $now = now();

    $connection->table('transactions')->insert([
        [
            'netsuite_id' => 1172768,
            'tranid' => 'SO27799',
            'other_ref_num' => '4-28-26',
            'type' => 'SalesOrd',
            'status' => 'G',
            'trandate' => '2026-04-28',
            'total' => '1829.05',
            'foreign_total' => '1829.05',
            'currency' => 'USD',
            'billing_address' => 'Appliance Pts Ctr A-0230'.PHP_EOL.'1470 New State Hwy'.PHP_EOL.'Unit 7'.PHP_EOL.'Raynham MA 02767'.PHP_EOL.'United States',
            'shipping_address' => 'Appliance Pts Ctr A-0230'.PHP_EOL.'1470 New State Hwy'.PHP_EOL.'Unit 7'.PHP_EOL.'Raynham MA 02767'.PHP_EOL.'United States',
            'terms_id' => '9',
            'terms_name' => 'Credit Card at Time of Purchase',
            'ship_date' => '2026-04-28',
            'ship_method_id' => '410',
            'ship_method_name' => 'Best Way',
            'memo' => 'Thank you for the order.',
            'last_modified_at' => '2026-04-28 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'netsuite_id' => 1173101,
            'tranid' => 'IF50051',
            'other_ref_num' => null,
            'type' => 'ItemShip',
            'status' => 'C',
            'trandate' => '2026-04-28',
            'total' => '0.00',
            'foreign_total' => '0.00',
            'currency' => 'USD',
            'billing_address' => null,
            'shipping_address' => 'Appliance Pts Ctr A-0230'.PHP_EOL.'1470 New State Hwy',
            'terms_id' => null,
            'terms_name' => null,
            'ship_date' => '2026-04-28',
            'ship_method_id' => null,
            'ship_method_name' => null,
            'memo' => null,
            'last_modified_at' => '2026-04-28 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $connection->table('transaction_lines')->insert([
        [
            'transaction_netsuite_id' => 1172768,
            'line_id' => '0',
            'item_id' => null,
            'item_name' => null,
            'item_number' => null,
            'description' => null,
            'quantity' => '0.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '0.0000',
            'amount' => '1829.05',
            'memo' => 'Thank you for the order.',
            'is_mainline' => true,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'transaction_netsuite_id' => 1172768,
            'line_id' => '1',
            'item_id' => 1910,
            'item_name' => 'WB31X5013CM',
            'item_number' => 'WB31X5013CM',
            'description' => '6" Ring',
            'quantity' => '-4.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '0.9400',
            'amount' => '-3.76',
            'memo' => '6" Ring',
            'is_mainline' => false,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'transaction_netsuite_id' => 1172768,
            'line_id' => '2',
            'item_id' => 9999,
            'item_name' => 'BULK',
            'item_number' => 'BULK',
            'description' => 'Bulk order line',
            'quantity' => '-1.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '1825.2900',
            'amount' => '-1825.29',
            'memo' => 'Bulk order line',
            'is_mainline' => false,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $connection->table('transaction_links')->insert([
        [
            'link_key' => '1172768:1:1173101:1:ShipRcpt',
            'previous_transaction_netsuite_id' => 1172768,
            'previous_line_id' => '1',
            'previous_transaction_type' => 'SalesOrd',
            'previous_transaction_number' => 'SO27799',
            'previous_last_modified_at' => '2026-04-28 12:00:00',
            'next_transaction_netsuite_id' => 1173101,
            'next_line_id' => '1',
            'next_transaction_type' => 'ItemShip',
            'next_transaction_number' => 'IF50051',
            'next_last_modified_at' => '2026-04-28 12:00:00',
            'link_type' => 'ShipRcpt',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $connection->table('transaction_tracking_numbers')->insert([
        [
            'transaction_netsuite_id' => 1173101,
            'tracking_number' => '1Z6A43370340902799',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    Livewire::test('components::company-transaction-detail', [
        'snapshotId' => $snapshot->id,
        'transactionId' => 1172768,
        'types' => ['SalesOrd'],
        'documentLabel' => 'Sales Order',
        'numberLabel' => 'Order #',
    ])
        ->assertSee('SO27799')
        ->assertSee('Sales Order')
        ->assertSee('Billed')
        ->assertSee('Appliance Pts Ctr A-0230')
        ->assertSee('Summary')
        ->assertSee('$1,829.05')
        ->assertSee('$0.00')
        ->assertSee('4-28-26')
        ->assertSee('Thank you for the order.')
        ->assertSee('Credit Card at Time of Purchase')
        ->assertSee('04/28/2026')
        ->assertSee('Best Way')
        ->assertSee('1Z6A43370340902799')
        ->assertSee('WB31X5013CM')
        ->assertSee('6&quot; Ring', false)
        ->assertSee('$0.94')
        ->assertSee('$3.76')
        ->assertDontSee('IF50051');
});

it('moves shipping method lines into freight instead of the item table', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 256,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);
    $now = now();

    $connection->table('transactions')->insert([
        'netsuite_id' => 1224564,
        'tranid' => 'SO28751',
        'other_ref_num' => '11520',
        'type' => 'SalesOrd',
        'status' => 'G',
        'trandate' => '2026-06-25',
        'total' => '142.72',
        'foreign_total' => '142.72',
        'currency' => 'USD',
        'billing_address' => 'Appliance Pts Plus A-0320',
        'shipping_address' => 'Appliance Pts Plus A-0320',
        'terms_id' => '2',
        'terms_name' => 'Net 30',
        'ship_date' => '2026-06-25',
        'ship_method_id' => '410',
        'ship_method_name' => 'Best Way',
        'memo' => 'Thank you for your order!',
        'last_modified_at' => '2026-06-26 00:00:00',
        'raw_payload' => '{}',
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('transaction_lines')->insert([
        [
            'transaction_netsuite_id' => 1224564,
            'line_id' => '1',
            'item_id' => 214,
            'item_name' => 'DA97-15217DCM',
            'item_number' => 'DA97-15217DCM',
            'description' => 'Ice Maker',
            'quantity' => '-2.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '54.9900',
            'amount' => '-109.98',
            'memo' => 'Ice Maker',
            'is_mainline' => false,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'transaction_netsuite_id' => 1224564,
            'line_id' => '2',
            'item_id' => 294,
            'item_name' => '3392519CM',
            'item_number' => '3392519CM',
            'description' => 'Thermal Fuse',
            'quantity' => '-15.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '0.8500',
            'amount' => '-12.75',
            'memo' => 'Thermal Fuse',
            'is_mainline' => false,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $shippingLineId = $connection->table('transaction_lines')->insertGetId([
        'transaction_netsuite_id' => 1224564,
        'line_id' => '3',
        'item_id' => 410,
        'item_name' => null,
        'item_number' => null,
        'description' => null,
        'quantity' => '-1.0000',
        'quantity_backordered' => '0.0000',
        'rate' => '19.9900',
        'amount' => '-19.99',
        'memo' => null,
        'is_mainline' => false,
        'is_tax_line' => false,
        'is_discount_line' => false,
        'line_type' => null,
        'raw_payload' => '{}',
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $repository = app(CompanySnapshotTransactionDetailRepository::class);
    $transaction = $repository->find($snapshot, 1224564);
    $displayLines = $repository->displayLines($snapshot, 1224564);
    $totals = $repository->totals($transaction, $displayLines, $repository->lines($snapshot, 1224564));

    expect($displayLines->pluck('line_id')->all())->toBe(['1', '2'])
        ->and($totals['subtotal'])->toBe(122.73)
        ->and($totals['freight'])->toBe(19.99)
        ->and($totals['total'])->toBe(142.72);

    Livewire::test('components::company-transaction-detail', [
        'snapshotId' => $snapshot->id,
        'transactionId' => 1224564,
        'types' => ['SalesOrd'],
        'documentLabel' => 'Sales Order',
        'numberLabel' => 'Order #',
    ])
        ->assertSee('SO28751')
        ->assertSee('DA97-15217DCM')
        ->assertSee('$122.73')
        ->assertSee('$19.99')
        ->assertSee('$142.72')
        ->assertDontSeeHtml('transaction-line-'.$shippingLineId);
});

it('hides zero amount ship method marker lines from transaction details', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 16,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);
    $now = now();

    $connection->table('transactions')->insert([
        'netsuite_id' => 1233802,
        'tranid' => 'INV50319',
        'other_ref_num' => 'PO-30001',
        'type' => 'CustInvc',
        'status' => 'B',
        'trandate' => '2026-07-08',
        'total' => '189.15',
        'foreign_total' => '189.15',
        'currency' => 'USD',
        'billing_address' => 'Appliance Pts Ctr A-0230',
        'shipping_address' => 'Appliance Pts Ctr A-0230',
        'terms_id' => '9',
        'terms_name' => 'Credit Card at Time of Purchase',
        'ship_date' => '2026-07-08',
        'ship_method_id' => '4',
        'ship_method_name' => 'UPS Ground',
        'memo' => 'Thank you!',
        'last_modified_at' => '2026-07-08 12:00:00',
        'raw_payload' => '{}',
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('transaction_lines')->insert([
        [
            'transaction_netsuite_id' => 1233802,
            'line_id' => '1',
            'item_id' => 272,
            'item_name' => '279827CM',
            'item_number' => '279827CM',
            'description' => 'Motor - Dryer',
            'quantity' => '-5.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '37.8300',
            'amount' => '-189.15',
            'memo' => 'Motor - Dryer',
            'is_mainline' => false,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $shippingLineId = $connection->table('transaction_lines')->insertGetId([
        'transaction_netsuite_id' => 1233802,
        'line_id' => '2',
        'item_id' => 4,
        'item_name' => null,
        'item_number' => null,
        'description' => 'UPS Ground',
        'quantity' => '-1.0000',
        'quantity_backordered' => '0.0000',
        'rate' => '0.0000',
        'amount' => '0.00',
        'memo' => 'UPS Ground',
        'is_mainline' => false,
        'is_tax_line' => false,
        'is_discount_line' => false,
        'line_type' => null,
        'raw_payload' => '{}',
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $repository = app(CompanySnapshotTransactionDetailRepository::class);

    expect($repository->displayLines($snapshot, 1233802)->pluck('line_id')->all())->toBe(['1']);

    Livewire::test('components::company-transaction-detail', [
        'snapshotId' => $snapshot->id,
        'transactionId' => 1233802,
        'types' => ['CustInvc'],
        'documentLabel' => 'Invoice',
        'numberLabel' => 'Invoice #',
    ])
        ->assertSee('INV50319')
        ->assertSee('279827CM')
        ->assertSee('Motor - Dryer')
        ->assertDontSeeHtml('transaction-line-'.$shippingLineId);
});

it('uses item name as the line item number fallback for credit memos', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 16,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);
    $now = now();
    $description = 'Warranty claim WCL-12345 for sealed system failure submitted by customer APOLLO-RETURN-2026';

    $connection->table('transactions')->insert([
        'netsuite_id' => 1180001,
        'tranid' => 'CM1002',
        'other_ref_num' => 'PO-1002',
        'type' => 'CustCred',
        'status' => 'B',
        'trandate' => '2026-05-01',
        'total' => '-42.50',
        'foreign_total' => '-42.50',
        'currency' => 'USD',
        'billing_address' => 'Appliance Pts Ctr A-0230',
        'shipping_address' => 'Appliance Pts Ctr A-0230',
        'terms_id' => '9',
        'terms_name' => 'Credit Card at Time of Purchase',
        'ship_date' => null,
        'ship_method_id' => null,
        'ship_method_name' => null,
        'memo' => 'Warranty claim credit',
        'last_modified_at' => '2026-05-01 12:00:00',
        'raw_payload' => '{}',
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('transaction_lines')->insert([
        [
            'transaction_netsuite_id' => 1180001,
            'line_id' => '0',
            'item_id' => null,
            'item_name' => null,
            'item_number' => null,
            'description' => null,
            'quantity' => '0.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '0.0000',
            'amount' => '-42.50',
            'memo' => 'Warranty claim credit',
            'is_mainline' => true,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'transaction_netsuite_id' => 1180001,
            'line_id' => '1',
            'item_id' => null,
            'item_name' => 'DC97-14486ACM',
            'item_number' => null,
            'description' => $description,
            'quantity' => '-1.0000',
            'quantity_backordered' => '0.0000',
            'rate' => '42.5000',
            'amount' => '-42.50',
            'memo' => $description,
            'is_mainline' => false,
            'is_tax_line' => false,
            'is_discount_line' => false,
            'line_type' => null,
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    Livewire::test('components::company-transaction-detail', [
        'snapshotId' => $snapshot->id,
        'transactionId' => 1180001,
        'types' => ['CustCred'],
        'documentLabel' => 'Credit Memo',
        'numberLabel' => 'Credit Memo #',
    ])
        ->assertSee('CM1002')
        ->assertSee('Credit Memo')
        ->assertSee('Item No.')
        ->assertSee('DC97-14486ACM')
        ->assertSee('Description')
        ->assertSee('Qty')
        ->assertSee('$42.50')
        ->assertSeeHtml('title="'.e($description).'"')
        ->assertSeeHtml('class="block max-w-full truncate"')
        ->assertDontSee('B/O');
});

it('queues a targeted record refresh from the synced timestamp control', function (): void {
    Queue::fake();

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 16,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now()->subDays(4),
        'summary_synced_at' => now()->subDays(4),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);
    $now = now()->subDays(4);

    $connection->table('transactions')->insert([
        'netsuite_id' => 1233802,
        'tranid' => 'SO30001',
        'other_ref_num' => 'PO-30001',
        'type' => 'SalesOrd',
        'status' => 'G',
        'trandate' => '2026-07-18',
        'total' => '129.97',
        'foreign_total' => '129.97',
        'currency' => 'USD',
        'billing_address' => 'Appliance Pts Ctr A-0230',
        'shipping_address' => 'Appliance Pts Ctr A-0230',
        'terms_id' => '9',
        'terms_name' => 'Credit Card at Time of Purchase',
        'ship_date' => '2026-07-18',
        'ship_method_id' => '410',
        'ship_method_name' => 'Best Way',
        'memo' => 'Thank you for the order.',
        'last_modified_at' => '2026-07-18 12:00:00',
        'raw_payload' => '{}',
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::test('components::company-transaction-detail', [
        'snapshotId' => $snapshot->id,
        'transactionId' => 1233802,
        'types' => ['SalesOrd'],
        'documentLabel' => 'Sales Order',
        'numberLabel' => 'Order #',
    ])
        ->assertSee('Synced')
        ->assertSeeHtml('text-xs')
        ->assertSeeHtml('cursor-pointer')
        ->call('refreshRecord')
        ->assertSee('Refreshing record')
        ->assertSeeHtml('animate-pulse')
        ->assertDontSee('Synced');

    Queue::assertPushed(
        SyncCompanySnapshotTransaction::class,
        fn (SyncCompanySnapshotTransaction $job): bool => $job->companySnapshotId === $snapshot->id
            && $job->netsuiteTransactionId === 1233802,
    );
});

it('renders the sales order show page with the shared transaction detail component', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 16,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);
    $now = now();

    $connection->table('transactions')->insert([
        'netsuite_id' => 1172768,
        'tranid' => 'SO27799',
        'other_ref_num' => '4-28-26',
        'type' => 'SalesOrd',
        'status' => 'G',
        'trandate' => '2026-04-28',
        'total' => '1829.05',
        'foreign_total' => '1829.05',
        'currency' => 'USD',
        'billing_address' => 'Appliance Pts Ctr A-0230',
        'shipping_address' => 'Appliance Pts Ctr A-0230',
        'terms_id' => '9',
        'terms_name' => 'Credit Card at Time of Purchase',
        'ship_date' => '2026-04-28',
        'ship_method_id' => '410',
        'ship_method_name' => 'Best Way',
        'memo' => 'Thank you for the order.',
        'last_modified_at' => '2026-04-28 12:00:00',
        'raw_payload' => '{}',
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::test('pages::company.sales-orders.show', [
        'company' => '16',
        'transaction' => '1172768',
    ])
        ->assertSet('netsuiteCompanyId', 16)
        ->assertSet('transactionId', 1172768)
        ->assertSee('SO27799')
        ->assertSee('Sales Order')
        ->assertSee('Credit Card at Time of Purchase');
});
