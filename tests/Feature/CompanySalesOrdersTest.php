<?php

use App\Jobs\SyncCompanySnapshotMeta;
use App\Jobs\SyncCompanySnapshotTransactions;
use App\Models\CompanySnapshot;
use App\Models\User;
use App\Services\CompanySnapshots\CompanySnapshotDatabaseManager;
use App\Services\CompanySnapshots\CompanySnapshotSalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    config([
        'company-snapshots.path' => storage_path('framework/testing/company-snapshots'),
    ]);

    File::deleteDirectory(storage_path('framework/testing/company-snapshots'));
});

it('loads the company snapshot and queues a stale refresh from the sales orders page', function (): void {
    Queue::fake();

    Livewire::test('pages::company.sales-orders', ['company' => '286'])
        ->assertSet('netsuiteCompanyId', 286);

    $snapshot = CompanySnapshot::query()->where('netsuite_company_id', 286)->firstOrFail();

    Queue::assertPushed(
        SyncCompanySnapshotMeta::class,
        fn (SyncCompanySnapshotMeta $job): bool => $job->companySnapshotId === $snapshot->id,
    );
});

it('queues a transaction refresh for sales order data older than one day', function (): void {
    Queue::fake();

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now()->subDays(2),
        'summary_synced_at' => now()->subDays(2),
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('pages::company.sales-orders', ['company' => '286'])
        ->assertSet('snapshotId', $snapshot->id);

    Queue::assertPushed(
        SyncCompanySnapshotTransactions::class,
        fn (SyncCompanySnapshotTransactions $job): bool => $job->companySnapshotId === $snapshot->id,
    );
});

it('queues the missing transaction refresh while the sales orders table polls', function (): void {
    Queue::fake();
    Cache::flush();

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => null,
        'summary_synced_at' => null,
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->call('refreshSyncState');

    Queue::assertPushed(
        SyncCompanySnapshotTransactions::class,
        fn (SyncCompanySnapshotTransactions $job): bool => $job->companySnapshotId === $snapshot->id,
    );
});

it('throttles transaction refresh queueing while the sales orders table polls', function (): void {
    Queue::fake();
    Cache::flush();

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => null,
        'summary_synced_at' => null,
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->call('refreshSyncState')
        ->call('refreshSyncState');

    Queue::assertPushed(SyncCompanySnapshotTransactions::class, 1);
});

it('renders paginated NetSuite sales orders from the company snapshot', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    $connection->table('transactions')->insert([
        [
            'netsuite_id' => 9001,
            'tranid' => 'INV1001',
            'other_ref_num' => null,
            'type' => 'CustInvc',
            'status' => 'Paid In Full',
            'trandate' => '2026-01-01',
            'total' => '1250.50',
            'foreign_total' => '1250.50',
            'currency' => 'USD',
            'memo' => 'Invoice',
            'last_modified_at' => '2026-01-02 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-02 12:05:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 9002,
            'tranid' => 'SO999',
            'other_ref_num' => 'PO-0999',
            'type' => 'SalesOrd',
            'status' => 'B',
            'trandate' => '2026-01-03',
            'total' => '250.00',
            'foreign_total' => '250.00',
            'currency' => 'USD',
            'memo' => 'Order',
            'last_modified_at' => '2026-01-03 16:05:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-03 16:10:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 9003,
            'tranid' => 'SO1000',
            'other_ref_num' => 'PO-1000',
            'type' => 'SalesOrd',
            'status' => 'G',
            'trandate' => '2026-01-02',
            'total' => '150.00',
            'foreign_total' => '150.00',
            'currency' => '1',
            'memo' => 'Order',
            'last_modified_at' => '2026-01-02 16:05:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-02 16:10:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(app(CompanySnapshotSalesOrderRepository::class)->paginate($snapshot))
        ->toBeInstanceOf(LengthAwarePaginator::class);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->assertSet('sortBy', 'sales_order_number')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeHtmlInOrder(['SO1000', 'SO999'])
        ->assertSee('PO-1000')
        ->assertSee('Jan 3, 2026')
        ->assertSee('Pending Fulfillment')
        ->assertSee('Billed')
        ->assertSee('$250.00')
        ->assertSee('$150.00')
        ->assertSee('Order actions')
        ->assertDontSee('Synced At')
        ->assertDontSee('Last Modified')
        ->assertDontSee('4:05 PM')
        ->assertDontSee('4:10 PM')
        ->assertDontSee('INV1001')
        ->assertDontSeeText('G')
        ->assertDontSee('wire:poll.visible.5s', false);
});

it('sorts sales orders by the selected sortable column', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    $connection->table('transactions')->insert([
        [
            'netsuite_id' => 9002,
            'tranid' => 'SO999',
            'other_ref_num' => 'PO-0999',
            'type' => 'SalesOrd',
            'status' => 'Pending Fulfillment',
            'trandate' => '2026-01-03',
            'total' => '250.00',
            'foreign_total' => '250.00',
            'currency' => 'USD',
            'memo' => 'Order',
            'last_modified_at' => '2026-01-03 16:05:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-03 16:10:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 9003,
            'tranid' => 'SO1000',
            'other_ref_num' => 'PO-1000',
            'type' => 'SalesOrd',
            'status' => 'Pending Approval',
            'trandate' => '2026-01-02',
            'total' => '150.00',
            'foreign_total' => '150.00',
            'currency' => 'USD',
            'memo' => 'Order',
            'last_modified_at' => '2026-01-02 16:05:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-02 16:10:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->assertSeeHtmlInOrder(['SO1000', 'SO999'])
        ->call('sort', 'date')
        ->assertSet('sortBy', 'date')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeHtmlInOrder(['SO999', 'SO1000'])
        ->call('sort', 'date')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeHtmlInOrder(['SO1000', 'SO999'])
        ->call('sort', 'po_number')
        ->assertSet('sortBy', 'po_number')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeHtmlInOrder(['PO-1000', 'PO-0999']);
});

it('searches sales orders by document number or PO number', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    $connection->table('transactions')->insert([
        [
            'netsuite_id' => 9002,
            'tranid' => 'SO999',
            'other_ref_num' => 'PO-0999',
            'type' => 'SalesOrd',
            'status' => 'B',
            'trandate' => '2026-01-03',
            'total' => '250.00',
            'foreign_total' => '250.00',
            'currency' => 'USD',
            'memo' => 'Order',
            'last_modified_at' => '2026-01-03 16:05:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-03 16:10:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 9003,
            'tranid' => 'SO1000',
            'other_ref_num' => 'PO-1000',
            'type' => 'SalesOrd',
            'status' => 'G',
            'trandate' => '2026-01-02',
            'total' => '150.00',
            'foreign_total' => '150.00',
            'currency' => 'USD',
            'memo' => 'Order',
            'last_modified_at' => '2026-01-02 16:05:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-02 16:10:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->set('search', 'PO-0999')
        ->assertSee('SO999')
        ->assertDontSee('SO1000')
        ->set('search', 'SO1000')
        ->assertSee('SO1000')
        ->assertDontSee('SO999');
});

it('links sales order actions to filtered invoice and credit memo tables', function (): void {
    Permission::findOrCreate('view invoice');

    $user = User::factory()->create();
    $user->givePermissionTo('view invoice');
    $this->actingAs($user);

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);
    $now = now();

    $connection->table('transactions')->insert([
        [
            'netsuite_id' => 9001,
            'tranid' => 'SO1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'SalesOrd',
            'status' => 'G',
            'trandate' => '2026-01-03',
            'total' => '1250.50',
            'foreign_total' => '1250.50',
            'currency' => 'USD',
            'memo' => 'Sales order',
            'last_modified_at' => '2026-01-03 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'netsuite_id' => 8001,
            'tranid' => 'INV1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'CustInvc',
            'status' => 'B',
            'trandate' => '2026-01-04',
            'total' => '625.25',
            'foreign_total' => '625.25',
            'currency' => 'USD',
            'memo' => 'Invoice',
            'last_modified_at' => '2026-01-04 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'netsuite_id' => 8002,
            'tranid' => 'INV1002',
            'other_ref_num' => 'PO-1001',
            'type' => 'CustInvc',
            'status' => 'B',
            'trandate' => '2026-01-05',
            'total' => '625.25',
            'foreign_total' => '625.25',
            'currency' => 'USD',
            'memo' => 'Backorder invoice',
            'last_modified_at' => '2026-01-05 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'netsuite_id' => 8003,
            'tranid' => 'CM1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'CustCred',
            'status' => 'B',
            'trandate' => '2026-01-06',
            'total' => '-100.00',
            'foreign_total' => '-100.00',
            'currency' => 'USD',
            'memo' => 'Credit memo',
            'last_modified_at' => '2026-01-06 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $connection->table('transaction_links')->insert([
        [
            'link_key' => '9001:1:8001:1:OrdBill',
            'previous_transaction_netsuite_id' => 9001,
            'previous_line_id' => '1',
            'previous_transaction_type' => 'SalesOrd',
            'previous_transaction_number' => 'SO1001',
            'previous_last_modified_at' => '2026-01-03 12:00:00',
            'next_transaction_netsuite_id' => 8001,
            'next_line_id' => '1',
            'next_transaction_type' => 'CustInvc',
            'next_transaction_number' => 'INV1001',
            'next_last_modified_at' => '2026-01-04 12:00:00',
            'link_type' => 'OrdBill',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'link_key' => '9001:2:8002:1:OrdBill',
            'previous_transaction_netsuite_id' => 9001,
            'previous_line_id' => '2',
            'previous_transaction_type' => 'SalesOrd',
            'previous_transaction_number' => 'SO1001',
            'previous_last_modified_at' => '2026-01-03 12:00:00',
            'next_transaction_netsuite_id' => 8002,
            'next_line_id' => '1',
            'next_transaction_type' => 'CustInvc',
            'next_transaction_number' => 'INV1002',
            'next_last_modified_at' => '2026-01-05 12:00:00',
            'link_type' => 'OrdBill',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'link_key' => '8002:1:8003:1:Credit',
            'previous_transaction_netsuite_id' => 8002,
            'previous_line_id' => '1',
            'previous_transaction_type' => 'CustInvc',
            'previous_transaction_number' => 'INV1002',
            'previous_last_modified_at' => '2026-01-05 12:00:00',
            'next_transaction_netsuite_id' => 8003,
            'next_line_id' => '1',
            'next_transaction_type' => 'CustCred',
            'next_transaction_number' => 'CM1001',
            'next_last_modified_at' => '2026-01-06 12:00:00',
            'link_type' => 'Credit',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->assertSee('View Invoices')
        ->assertSee('View Credit Memos')
        ->assertSee('related=8002%2C8001', false)
        ->assertSee('related=8003', false)
        ->assertSee('source=SO1001', false);
});

it('renders the last synced timestamp as a client-side relative timer', function (): void {
    $syncedAt = now()->subSeconds(30)->startOfSecond();

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => $syncedAt,
        'summary_synced_at' => now(),
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->assertSee('Last synced')
        ->assertSee("sales-orders-synced-at-{$syncedAt->getTimestamp()}", false)
        ->assertSee('x-data="relativeTime', false)
        ->assertSee($syncedAt->toIso8601String(), false)
        ->assertDontSee('setTimeout', false)
        ->assertDontSee('elapsedSeconds', false)
        ->assertDontSee('wire:poll.visible.5s', false);
});

it('shows when stale sales order data is checking for updates', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now()->subDays(2),
        'summary_synced_at' => now()->subDays(2),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    $connection->table('transactions')->insert([
        'netsuite_id' => 9002,
        'tranid' => 'SO999',
        'other_ref_num' => 'PO-0999',
        'type' => 'SalesOrd',
        'status' => 'B',
        'trandate' => '2026-01-03',
        'total' => '250.00',
        'foreign_total' => '250.00',
        'currency' => 'USD',
        'memo' => 'Order',
        'last_modified_at' => '2026-01-03 16:05:00',
        'raw_payload' => '{}',
        'synced_at' => '2026-01-03 16:10:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->assertSee('Checking for updates')
        ->assertSee('Checking now')
        ->assertSee('Last synced')
        ->assertSee('wire:poll.visible.5s', false);
});

it('shows when sales order data is actively refreshing', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now()->subDays(2),
        'summary_synced_at' => now()->subDays(2),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    $connection->table('transactions')->insert([
        'netsuite_id' => 9002,
        'tranid' => 'SO999',
        'other_ref_num' => 'PO-0999',
        'type' => 'SalesOrd',
        'status' => 'B',
        'trandate' => '2026-01-03',
        'total' => '250.00',
        'foreign_total' => '250.00',
        'currency' => 'USD',
        'memo' => 'Order',
        'last_modified_at' => '2026-01-03 16:05:00',
        'raw_payload' => '{}',
        'synced_at' => '2026-01-03 16:10:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->assertSee('Refreshing data')
        ->assertSee('Checking now')
        ->assertSee('Last synced')
        ->assertSee('wire:poll.visible.5s', false);
});

it('shows a lightweight syncing state while transaction sync is pending', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
        'transactions_synced_at' => null,
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('components::company-sales-orders-table', ['snapshotId' => $snapshot->id])
        ->assertSee('Sales orders are syncing')
        ->assertSee('We are checking our servers. This page will update automatically as soon as sales orders are available.')
        ->assertSee('Waiting for next check')
        ->assertSee('Checking now')
        ->assertSee('Started')
        ->assertSee('x-data="relativeTime', false)
        ->assertSee('wire:poll.visible.5s', false);
});
