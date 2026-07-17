<?php

use App\Jobs\SyncCompanySnapshotMeta;
use App\Jobs\SyncCompanySnapshotTransactions;
use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotDatabaseManager;
use App\Services\CompanySnapshots\CompanySnapshotInvoiceRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    config([
        'company-snapshots.path' => storage_path('framework/testing/company-snapshots'),
    ]);

    File::deleteDirectory(storage_path('framework/testing/company-snapshots'));
});

it('loads the company snapshot and queues a stale refresh from the invoices page', function (): void {
    Queue::fake();

    Livewire::test('pages::company.invoices', ['company' => '286'])
        ->assertSet('netsuiteCompanyId', 286);

    $snapshot = CompanySnapshot::query()->where('netsuite_company_id', 286)->firstOrFail();

    Queue::assertPushed(
        SyncCompanySnapshotMeta::class,
        fn (SyncCompanySnapshotMeta $job): bool => $job->companySnapshotId === $snapshot->id,
    );
});

it('queues a transaction refresh for invoice data older than one day', function (): void {
    Queue::fake();

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now()->subDays(2),
        'summary_synced_at' => now()->subDays(2),
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('pages::company.invoices', ['company' => '286'])
        ->assertSet('snapshotId', $snapshot->id);

    Queue::assertPushed(
        SyncCompanySnapshotTransactions::class,
        fn (SyncCompanySnapshotTransactions $job): bool => $job->companySnapshotId === $snapshot->id,
    );
});

it('queues the missing transaction refresh while the invoices table polls', function (): void {
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

    Livewire::test('components::company-invoices-table', ['snapshotId' => $snapshot->id])
        ->call('refreshSyncState');

    Queue::assertPushed(
        SyncCompanySnapshotTransactions::class,
        fn (SyncCompanySnapshotTransactions $job): bool => $job->companySnapshotId === $snapshot->id,
    );
});

it('throttles transaction refresh queueing while the invoices table polls', function (): void {
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

    Livewire::test('components::company-invoices-table', ['snapshotId' => $snapshot->id])
        ->call('refreshSyncState')
        ->call('refreshSyncState');

    Queue::assertPushed(SyncCompanySnapshotTransactions::class, 1);
});

it('renders paginated invoices and credit memos from the company snapshot', function (): void {
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
            'netsuite_id' => 8001,
            'tranid' => 'INV1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'CustInvc',
            'status' => 'B',
            'trandate' => '2026-01-03',
            'total' => '1250.50',
            'foreign_total' => '1250.50',
            'currency' => 'USD',
            'memo' => 'Invoice',
            'last_modified_at' => '2026-01-03 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-03 12:05:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 8002,
            'tranid' => 'CM1002',
            'other_ref_num' => 'PO-1002',
            'type' => 'CustCred',
            'status' => 'B',
            'trandate' => '2026-01-04',
            'total' => '-100.00',
            'foreign_total' => '-100.00',
            'currency' => '1',
            'memo' => 'Credit memo',
            'last_modified_at' => '2026-01-04 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-04 12:05:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 9001,
            'tranid' => 'SO1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'SalesOrd',
            'status' => 'G',
            'trandate' => '2026-01-05',
            'total' => '250.00',
            'foreign_total' => '250.00',
            'currency' => 'USD',
            'memo' => 'Order',
            'last_modified_at' => '2026-01-05 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-05 12:05:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $invoiceRepository = app(CompanySnapshotInvoiceRepository::class);

    expect($invoiceRepository->paginate($snapshot))
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($invoiceRepository->isInvoiceType('CustInvc'))->toBeTrue()
        ->and($invoiceRepository->isInvoiceType('CustCred'))->toBeTrue()
        ->and($invoiceRepository->isInvoiceType('SalesOrd'))->toBeFalse();

    Livewire::test('components::company-invoices-table', ['snapshotId' => $snapshot->id])
        ->assertSet('sortBy', 'date')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeHtmlInOrder(['CM1002', 'INV1001'])
        ->assertSee('PO-1002')
        ->assertSee('Jan 4, 2026')
        ->assertSee('Invoice')
        ->assertSee('Credit Memo')
        ->assertSee('Paid In Full')
        ->assertSee('Fully Applied')
        ->assertSee('$1,250.50')
        ->assertSee('-$100.00')
        ->assertSee('Billing document actions')
        ->assertDontSee('Synced At')
        ->assertDontSee('Last Modified')
        ->assertDontSee('12:05 PM')
        ->assertDontSee('SO1001')
        ->assertDontSee('wire:poll.visible.5s', false);
});

it('sorts invoices by the selected sortable column', function (): void {
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
            'netsuite_id' => 8001,
            'tranid' => 'INV1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'CustInvc',
            'status' => 'A',
            'trandate' => '2026-01-03',
            'total' => '1250.50',
            'foreign_total' => '1250.50',
            'currency' => 'USD',
            'memo' => 'Invoice',
            'last_modified_at' => '2026-01-03 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-03 12:05:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 8002,
            'tranid' => 'INV1002',
            'other_ref_num' => 'PO-1002',
            'type' => 'CustInvc',
            'status' => 'B',
            'trandate' => '2026-01-04',
            'total' => '100.00',
            'foreign_total' => '100.00',
            'currency' => 'USD',
            'memo' => 'Invoice',
            'last_modified_at' => '2026-01-04 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => '2026-01-04 12:05:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Livewire::test('components::company-invoices-table', ['snapshotId' => $snapshot->id])
        ->assertSeeHtmlInOrder(['INV1002', 'INV1001'])
        ->call('sort', 'invoice_number')
        ->assertSet('sortBy', 'invoice_number')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeHtmlInOrder(['INV1002', 'INV1001'])
        ->call('sort', 'invoice_number')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeHtmlInOrder(['INV1001', 'INV1002'])
        ->call('sort', 'status')
        ->assertSet('sortBy', 'status')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeHtmlInOrder(['Open', 'Paid In Full']);
});

it('renders the invoice last synced timestamp as a client-side relative timer', function (): void {
    $syncedAt = now()->subSeconds(30)->startOfSecond();

    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => $syncedAt,
        'summary_synced_at' => now(),
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('components::company-invoices-table', ['snapshotId' => $snapshot->id])
        ->assertSee('Last synced')
        ->assertSee("invoices-synced-at-{$syncedAt->getTimestamp()}", false)
        ->assertSee('x-data="relativeTime', false)
        ->assertSee($syncedAt->toIso8601String(), false)
        ->assertDontSee('setTimeout', false)
        ->assertDontSee('elapsedSeconds', false)
        ->assertDontSee('wire:poll.visible.5s', false);
});

it('shows when stale invoice data is checking for updates', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now()->subDays(2),
        'summary_synced_at' => now()->subDays(2),
    ]);

    $connection = app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    $connection->table('transactions')->insert([
        'netsuite_id' => 8001,
        'tranid' => 'INV1001',
        'other_ref_num' => 'PO-1001',
        'type' => 'CustInvc',
        'status' => 'A',
        'trandate' => '2026-01-03',
        'total' => '1250.50',
        'foreign_total' => '1250.50',
        'currency' => 'USD',
        'memo' => 'Invoice',
        'last_modified_at' => '2026-01-03 12:00:00',
        'raw_payload' => '{}',
        'synced_at' => '2026-01-03 12:05:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('components::company-invoices-table', ['snapshotId' => $snapshot->id])
        ->assertSee('Checking for updates')
        ->assertSee('Checking now')
        ->assertSee('Last synced')
        ->assertSee('wire:poll.visible.5s', false);
});

it('shows a lightweight syncing state while invoice sync is pending', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
        'transactions_synced_at' => null,
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    Livewire::test('components::company-invoices-table', ['snapshotId' => $snapshot->id])
        ->assertSee('Invoices are syncing')
        ->assertSee('We are checking our servers. This page will update automatically as soon as invoices are available.')
        ->assertSee('Waiting for next check')
        ->assertSee('Checking now')
        ->assertSee('Started')
        ->assertSee('x-data="relativeTime', false)
        ->assertSee('wire:poll.visible.5s', false);
});
