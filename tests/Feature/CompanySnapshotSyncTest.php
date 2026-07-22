<?php

use App\Jobs\SyncCompanySnapshotMeta;
use App\Jobs\SyncCompanySnapshotTransactions;
use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotDatabaseManager;
use App\Services\CompanySnapshots\CompanySnapshotSummaryCompiler;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Searsandrew\BriarRose\BriarRoseManager;

beforeEach(function (): void {
    config([
        'briar-rose.account' => '5802217',
        'briar-rose.consumer_key' => 'ck',
        'briar-rose.consumer_secret' => 'cs',
        'briar-rose.token_id' => 'tk',
        'briar-rose.token_secret' => 'ts',
        'briar-rose.rest_base_url' => 'https://5802217.suitetalk.api.netsuite.com',
        'briar-rose.rest.retries.enabled' => false,
        'company-snapshots.path' => storage_path('framework/testing/company-snapshots'),
    ]);

    app()->forgetInstance(BriarRoseManager::class);
    File::deleteDirectory(storage_path('framework/testing/company-snapshots'));
    Http::preventStrayRequests();
});

it('creates a dynamic sqlite snapshot database for a company', function (): void {
    $snapshot = app(CompanySnapshotSyncer::class)->ensureSnapshot(286);

    expect($snapshot->netsuite_company_id)->toBe(286)
        ->and($snapshot->connection_name)->toBe('company_286')
        ->and(File::exists($snapshot->database_path))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasTable('meta'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasTable('transactions'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasColumn('transactions', 'other_ref_num'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasColumn('transactions', 'billing_address'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasColumn('transactions', 'terms_name'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasColumn('transactions', 'ship_method_name'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasTable('transaction_lines'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasColumn('transaction_lines', 'item_number'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasColumn('transaction_lines', 'quantity_backordered'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasTable('transaction_links'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasTable('transaction_tracking_numbers'))->toBeTrue()
        ->and(Schema::connection($snapshot->connection_name)->hasTable('sync_state'))->toBeTrue();
});

it('syncs company meta into the snapshot sqlite database and central summary', function (): void {
    Http::fake(fn (Request $request) => Http::response([
        'items' => [
            [
                'id' => '286',
                'entityid' => 'ACME-286',
                'account_number' => 'A-0121',
                'companyname' => 'Acme Industrial',
                'terms' => 'Net 30',
                'email' => 'ap@example.test',
                'phone' => '555-0100',
                'url' => 'https://example.test',
                'isinactive' => 'F',
                'entitystatus' => '13',
                'salesrep' => '1439',
                'datecreated' => '2025-01-01T00:00:00',
                'lastmodifieddate' => '2026-06-01T00:00:00',
            ],
        ],
        'hasMore' => false,
    ]));

    $syncer = app(CompanySnapshotSyncer::class);
    $snapshot = $syncer->ensureSnapshot(286);
    $syncer->syncMeta($snapshot);

    $connection = app(CompanySnapshotDatabaseManager::class)->connection($snapshot);

    expect(json_decode($connection->table('meta')->where('key', 'company_name')->value('value'), true))->toBe('Acme Industrial')
        ->and(json_decode($connection->table('meta')->where('key', 'terms')->value('value'), true))->toBe('Net 30')
        ->and(json_decode($connection->table('meta')->where('key', 'sales_rep_id')->value('value'), true))->toBe(1439)
        ->and($snapshot->refresh()->meta_synced_at)->not->toBeNull()
        ->and($snapshot->summary()->first()?->company_name)->toBe('Acme Industrial')
        ->and($snapshot->summary()->first()?->terms)->toBe('Net 30');
});

it('syncs transactions into sqlite and compiles central reporting summary', function (): void {
    Http::fake(function (Request $request) {
        $query = $request->data()['q'] ?? '';

        if (str_contains($query, 'FROM customer')) {
            return Http::response([
                'items' => [
                    [
                        'id' => '286',
                        'entityid' => 'ACME-286',
                        'account_number' => 'A-0121',
                        'companyname' => 'Acme Industrial',
                        'terms' => 'Net 30',
                        'email' => null,
                        'phone' => null,
                        'url' => null,
                        'isinactive' => 'F',
                        'entitystatus' => '13',
                        'salesrep' => '1439',
                        'datecreated' => null,
                        'lastmodifieddate' => null,
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'FROM nextTransactionLineLink')) {
            return Http::response([
                'items' => [
                    [
                        'previous_doc' => '9002',
                        'previous_line' => '1',
                        'next_doc' => '9001',
                        'next_line' => '1',
                        'link_type' => 'OrdBill',
                        'previous_type' => 'SalesOrd',
                        'previous_tranid' => 'SO1001',
                        'previous_lastmodifieddate' => now()->toDateTimeString(),
                        'next_type' => 'CustInvc',
                        'next_tranid' => 'INV1001',
                        'next_lastmodifieddate' => now()->toDateTimeString(),
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'FROM itemfulfillmentpackage')) {
            return Http::response([
                'items' => [
                    [
                        'transaction_id' => '9004',
                        'tracking_number' => '1Z999',
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'FROM itemfulfillmentpackage')) {
            return Http::response([
                'items' => [],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'FROM transactionline')) {
            return Http::response([
                'items' => [
                    [
                        'transaction_id' => '9001',
                        'line_id' => '1',
                        'item' => '456',
                        'item_number' => 'WIDGET',
                        'item_description' => 'Widget',
                        'quantity' => '2',
                        'quantitybackordered' => '0',
                        'rate' => '625.25',
                        'amount' => '1250.50',
                        'memo' => 'Invoice line',
                        'mainline' => 'F',
                        'taxline' => 'F',
                        'transactiondiscount' => 'F',
                        'transactionlinetype' => null,
                    ],
                    [
                        'transaction_id' => '9002',
                        'line_id' => '1',
                        'item' => '789',
                        'item_number' => 'BRACKET',
                        'item_description' => 'Bracket',
                        'quantity' => '1',
                        'quantitybackordered' => '0',
                        'rate' => '250',
                        'amount' => '250',
                        'memo' => 'Order line',
                        'mainline' => 'F',
                        'taxline' => 'F',
                        'transactiondiscount' => 'F',
                        'transactionlinetype' => null,
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        return Http::response([
            'items' => [
                [
                    'id' => '9001',
                    'tranid' => 'INV1001',
                    'type' => 'CustInvc',
                    'trandate' => '1/15/2026',
                    'status' => 'Paid In Full',
                    'memo' => 'Original invoice',
                    'total' => '1250.50',
                    'foreigntotal' => '1250.50',
                    'currency' => 'USD',
                    'billing_address' => 'Acme Industrial'.PHP_EOL.'1 Main St',
                    'shipping_address' => 'Acme Warehouse'.PHP_EOL.'2 Dock St',
                    'terms_id' => '9',
                    'terms_name' => 'Credit Card at Time of Purchase',
                    'shipdate' => '1/16/2026',
                    'ship_method_id' => '410',
                    'ship_method_name' => 'Best Way',
                    'lastmodifieddate' => now()->toDateTimeString(),
                ],
                [
                    'id' => '9002',
                    'tranid' => 'SO1001',
                    'otherrefnum' => 'PO-1001',
                    'type' => 'SalesOrd',
                    'trandate' => '12/31/2025',
                    'status' => 'Pending Fulfillment',
                    'memo' => 'Open order',
                    'total' => '250.00',
                    'foreigntotal' => '250.00',
                    'currency' => 'USD',
                    'billing_address' => 'Acme Industrial'.PHP_EOL.'1 Main St',
                    'shipping_address' => 'Acme Warehouse'.PHP_EOL.'2 Dock St',
                    'terms_id' => '9',
                    'terms_name' => 'Credit Card at Time of Purchase',
                    'shipdate' => '12/31/2025',
                    'ship_method_id' => '410',
                    'ship_method_name' => 'Best Way',
                    'lastmodifieddate' => now()->toDateTimeString(),
                ],
            ],
            'hasMore' => false,
        ]);
    });

    $syncer = app(CompanySnapshotSyncer::class);
    $snapshot = $syncer->ensureSnapshot(286);

    $syncer->syncMeta($snapshot);
    $syncer->syncTransactions($snapshot->refresh());
    $summary = app(CompanySnapshotSummaryCompiler::class)->compile($snapshot->refresh());

    $connection = app(CompanySnapshotDatabaseManager::class)->connection($snapshot);

    expect($connection->table('transactions')->count())->toBe(2)
        ->and($connection->table('transaction_lines')->count())->toBe(2)
        ->and($connection->table('transaction_links')->count())->toBe(1)
        ->and($connection->table('transaction_tracking_numbers')->count())->toBe(1)
        ->and($connection->table('transactions')->where('tranid', 'INV1001')->value('trandate'))->toBe('2026-01-15')
        ->and($connection->table('transactions')->where('tranid', 'SO1001')->value('other_ref_num'))->toBe('PO-1001')
        ->and($connection->table('transactions')->where('tranid', 'SO1001')->value('trandate'))->toBe('2025-12-31')
        ->and($connection->table('transactions')->where('tranid', 'SO1001')->value('terms_name'))->toBe('Credit Card at Time of Purchase')
        ->and($connection->table('transactions')->where('tranid', 'SO1001')->value('ship_method_name'))->toBe('Best Way')
        ->and($connection->table('transaction_lines')->where('transaction_netsuite_id', 9002)->value('item_number'))->toBe('BRACKET')
        ->and((float) $connection->table('transaction_lines')->where('transaction_netsuite_id', 9002)->value('quantity_backordered'))->toBe(0.0)
        ->and($connection->table('transaction_links')->where('previous_transaction_netsuite_id', 9002)->where('next_transaction_netsuite_id', 9001)->value('link_type'))->toBe('OrdBill')
        ->and($summary->transaction_count)->toBe(2)
        ->and($summary->invoice_total)->toBe('1250.50')
        ->and($summary->open_order_total)->toBe('250.00')
        ->and($summary->company_name)->toBe('Acme Industrial')
        ->and($summary->terms)->toBe('Net 30');
});

it('normalizes legacy transaction dates when ensuring a snapshot database', function (): void {
    $syncer = app(CompanySnapshotSyncer::class);
    $snapshot = $syncer->ensureSnapshot(286);
    $connection = app(CompanySnapshotDatabaseManager::class)->connection($snapshot);

    $connection->table('schema_info')->delete();
    $connection->table('schema_info')->insert([
        'version' => 1,
        'created_at' => now(),
    ]);

    $connection->table('transactions')->insert([
        [
            'netsuite_id' => 9001,
            'tranid' => 'SO1000',
            'other_ref_num' => 'PO-1000',
            'type' => 'SalesOrd',
            'status' => 'G',
            'trandate' => '7/1/2026',
            'total' => '150.00',
            'foreign_total' => '150.00',
            'currency' => 'USD',
            'memo' => 'Legacy order',
            'last_modified_at' => '2026-07-01 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'netsuite_id' => 9002,
            'tranid' => 'SO1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'SalesOrd',
            'status' => 'G',
            'trandate' => '12/1/2025',
            'total' => '250.00',
            'foreign_total' => '250.00',
            'currency' => 'USD',
            'memo' => 'Legacy order',
            'last_modified_at' => '2025-12-01 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app(CompanySnapshotDatabaseManager::class)->ensureDatabase($snapshot);

    $connection = app(CompanySnapshotDatabaseManager::class)->connection($snapshot);

    expect($connection->table('transactions')->where('tranid', 'SO1000')->value('trandate'))->toBe('2026-07-01')
        ->and($connection->table('transactions')->where('tranid', 'SO1001')->value('trandate'))->toBe('2025-12-01')
        ->and($connection->table('schema_info')->max('version'))->toBe(CompanySnapshot::SCHEMA_VERSION);
});

it('syncs only recently modified transactions when a transaction cursor exists', function (): void {
    $queries = [];

    Http::fake(function (Request $request) use (&$queries) {
        $query = $request->data()['q'] ?? '';
        $queries[] = $query;

        expect($query)->toContain("lastmodifieddate >= TO_DATE('2026-07-10 11:55:00', 'yyyy-mm-dd hh24:mi:ss')");

        if (str_contains($query, 'FROM nextTransactionLineLink')) {
            return Http::response([
                'items' => [
                    [
                        'previous_doc' => '9002',
                        'previous_line' => '1',
                        'next_doc' => '9003',
                        'next_line' => '1',
                        'link_type' => 'OrdBill',
                        'previous_type' => 'SalesOrd',
                        'previous_tranid' => 'SO1001',
                        'previous_lastmodifieddate' => '2026-07-10 12:10:00',
                        'next_type' => 'CustInvc',
                        'next_tranid' => 'INV1002',
                        'next_lastmodifieddate' => '2026-07-10 12:10:00',
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'FROM transactionline')) {
            return Http::response([
                'items' => [
                    [
                        'transaction_id' => '9002',
                        'line_id' => '1',
                        'item' => '789',
                        'item_number' => 'BRACKET',
                        'item_description' => 'Bracket',
                        'quantity' => '1',
                        'quantitybackordered' => '0',
                        'rate' => '250',
                        'amount' => '250',
                        'memo' => 'Updated order line',
                        'mainline' => 'F',
                        'taxline' => 'F',
                        'transactiondiscount' => 'F',
                        'transactionlinetype' => null,
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        return Http::response([
            'items' => [
                [
                    'id' => '9002',
                    'tranid' => 'SO1001',
                    'otherrefnum' => 'PO-1001',
                    'type' => 'SalesOrd',
                    'trandate' => '2026-07-10',
                    'status' => 'B',
                    'memo' => 'Updated order',
                    'total' => '250.00',
                    'foreigntotal' => '250.00',
                    'currency' => 'USD',
                    'lastmodifieddate' => '2026-07-10 12:10:00',
                ],
            ],
            'hasMore' => false,
        ]);
    });

    $syncer = app(CompanySnapshotSyncer::class);
    $snapshot = $syncer->ensureSnapshot(286);
    $connection = app(CompanySnapshotDatabaseManager::class)->connection($snapshot);

    $connection->table('transactions')->insert([
        'netsuite_id' => 9001,
        'tranid' => 'SO1000',
        'other_ref_num' => 'PO-1000',
        'type' => 'SalesOrd',
        'status' => 'G',
        'trandate' => '2026-07-09',
        'total' => '150.00',
        'foreign_total' => '150.00',
        'currency' => 'USD',
        'memo' => 'Existing order',
        'last_modified_at' => '2026-07-10 12:00:00',
        'raw_payload' => '{}',
        'synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $connection->table('sync_state')->insert([
        'scope' => 'transactions',
        'cursor_value' => '2026-07-10 12:00:00',
        'synced_at' => now(),
        'payload' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $syncer->syncTransactions($snapshot);

    $payload = json_decode((string) $connection->table('sync_state')->where('scope', 'transactions')->value('payload'), true);

    expect($queries)->toHaveCount(4)
        ->and($connection->table('transactions')->count())->toBe(2)
        ->and($connection->table('transactions')->where('netsuite_id', 9002)->value('memo'))->toBe('Updated order')
        ->and($connection->table('transaction_lines')->count())->toBe(1)
        ->and($connection->table('transaction_links')->count())->toBe(1)
        ->and($connection->table('sync_state')->where('scope', 'transactions')->value('cursor_value'))->toBe('2026-07-10 12:10:00')
        ->and($payload['mode'])->toBe('incremental')
        ->and($payload['modified_since'])->toBe('2026-07-10 11:55:00');
});

it('can force a full transaction sync even when a transaction cursor exists', function (): void {
    $queries = [];

    Http::fake(function (Request $request) use (&$queries) {
        $query = $request->data()['q'] ?? '';
        $queries[] = $query;

        expect($query)->not->toContain('TO_DATE');

        return Http::response([
            'items' => [],
            'hasMore' => false,
        ]);
    });

    $syncer = app(CompanySnapshotSyncer::class);
    $snapshot = $syncer->ensureSnapshot(286);
    $connection = app(CompanySnapshotDatabaseManager::class)->connection($snapshot);

    $connection->table('sync_state')->insert([
        'scope' => 'transactions',
        'cursor_value' => '2026-07-10 12:00:00',
        'synced_at' => now(),
        'payload' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $syncer->syncTransactions($snapshot, full: true);

    $payload = json_decode((string) $connection->table('sync_state')->where('scope', 'transactions')->value('payload'), true);

    expect($queries)->toHaveCount(4)
        ->and($payload['mode'])->toBe('full')
        ->and($payload['modified_since'])->toBeNull();
});

it('syncs a single transaction by id without advancing the company transaction cursor', function (): void {
    $queries = [];

    Http::fake(function (Request $request) use (&$queries) {
        $query = $request->data()['q'] ?? '';
        $queries[] = $query;

        expect($query)->not->toContain('TO_DATE');

        if (str_contains($query, 'FROM transactionline')) {
            expect($query)->toContain('transactionline.transaction = 1233802');

            return Http::response([
                'items' => [
                    [
                        'transaction_id' => '1233802',
                        'line_id' => '1',
                        'item' => '214',
                        'item_number' => 'DA97-15217DCM',
                        'item_description' => 'Ice Maker',
                        'quantity' => '2',
                        'quantitybackordered' => '0',
                        'rate' => '54.99',
                        'amount' => '109.98',
                        'memo' => 'Ice Maker',
                        'mainline' => 'F',
                        'taxline' => 'F',
                        'transactiondiscount' => 'F',
                        'transactionlinetype' => null,
                    ],
                    [
                        'transaction_id' => '1233802',
                        'line_id' => '2',
                        'item' => '410',
                        'item_number' => null,
                        'item_description' => null,
                        'quantity' => '1',
                        'quantitybackordered' => '0',
                        'rate' => '19.99',
                        'amount' => '19.99',
                        'memo' => null,
                        'mainline' => 'F',
                        'taxline' => 'F',
                        'transactiondiscount' => 'F',
                        'transactionlinetype' => null,
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'FROM nextTransactionLineLink')) {
            expect($query)->toContain('nextTransactionLineLink.previousDoc = 1233802');

            return Http::response([
                'items' => [
                    [
                        'previous_doc' => '1233802',
                        'previous_line' => '1',
                        'next_doc' => '1233810',
                        'next_line' => '1',
                        'link_type' => 'ShipRcpt',
                        'previous_type' => 'SalesOrd',
                        'previous_tranid' => 'SO30001',
                        'previous_lastmodifieddate' => '2026-07-18 12:00:00',
                        'next_type' => 'ItemShip',
                        'next_tranid' => 'IF50100',
                        'next_lastmodifieddate' => '2026-07-18 13:00:00',
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        if (str_contains($query, 'FROM itemfulfillmentpackage')) {
            expect($query)->toContain('fulfillmentTransaction.id IN (1233810)');

            return Http::response([
                'items' => [
                    [
                        'transaction_id' => '1233810',
                        'tracking_number' => '1Z123',
                    ],
                ],
                'hasMore' => false,
            ]);
        }

        expect($query)->toContain('FROM transaction')
            ->and($query)->toContain('id = 1233802');

        return Http::response([
            'items' => [
                [
                    'id' => '1233802',
                    'tranid' => 'SO30001',
                    'otherrefnum' => 'PO-30001',
                    'type' => 'SalesOrd',
                    'trandate' => '2026-07-18',
                    'status' => 'G',
                    'memo' => 'Targeted refresh order',
                    'total' => '129.97',
                    'foreigntotal' => '129.97',
                    'currency' => 'USD',
                    'billing_address' => 'Acme Industrial',
                    'shipping_address' => 'Acme Warehouse',
                    'terms_id' => '9',
                    'terms_name' => 'Credit Card at Time of Purchase',
                    'shipdate' => '2026-07-18',
                    'ship_method_id' => '410',
                    'ship_method_name' => 'Best Way',
                    'lastmodifieddate' => '2026-07-18 12:00:00',
                ],
            ],
            'hasMore' => false,
        ]);
    });

    $syncer = app(CompanySnapshotSyncer::class);
    $snapshot = $syncer->ensureSnapshot(286);
    $connection = app(CompanySnapshotDatabaseManager::class)->connection($snapshot);

    $connection->table('transactions')->insert([
        'netsuite_id' => 1233802,
        'tranid' => 'SO30001',
        'other_ref_num' => null,
        'type' => 'SalesOrd',
        'status' => 'B',
        'trandate' => '2026-07-18',
        'total' => '109.98',
        'foreign_total' => '109.98',
        'currency' => 'USD',
        'memo' => 'Stale order',
        'last_modified_at' => '2026-07-18 12:00:00',
        'raw_payload' => '{}',
        'synced_at' => now()->subDays(4),
        'created_at' => now()->subDays(4),
        'updated_at' => now()->subDays(4),
    ]);

    $syncer->syncTransaction($snapshot, 1233802);

    $payload = json_decode((string) $connection->table('sync_state')->where('scope', 'transaction:1233802')->value('payload'), true);

    expect($queries)->toHaveCount(4)
        ->and($connection->table('transactions')->where('netsuite_id', 1233802)->value('memo'))->toBe('Targeted refresh order')
        ->and($connection->table('transactions')->where('netsuite_id', 1233802)->value('other_ref_num'))->toBe('PO-30001')
        ->and($connection->table('transaction_lines')->where('transaction_netsuite_id', 1233802)->count())->toBe(2)
        ->and($connection->table('transaction_lines')->where('transaction_netsuite_id', 1233802)->where('line_id', '1')->value('item_number'))->toBe('DA97-15217DCM')
        ->and($connection->table('transaction_links')->where('previous_transaction_netsuite_id', 1233802)->where('next_transaction_netsuite_id', 1233810)->value('link_type'))->toBe('ShipRcpt')
        ->and($connection->table('transaction_tracking_numbers')->where('transaction_netsuite_id', 1233810)->value('tracking_number'))->toBe('1Z123')
        ->and($connection->table('sync_state')->where('scope', 'transactions')->exists())->toBeFalse()
        ->and($snapshot->refresh()->transactions_synced_at)->toBeNull()
        ->and($payload['mode'])->toBe('targeted')
        ->and($payload['line_count'])->toBe(2);
});

it('dispatches full transaction refresh jobs for weekly reconciliation', function (): void {
    Queue::fake();

    CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'transactions_synced_at' => now(),
    ]);

    Artisan::call('company-snapshots:refresh-full-transactions', ['--limit' => 10]);

    Queue::assertPushed(
        SyncCompanySnapshotTransactions::class,
        fn (SyncCompanySnapshotTransactions $job): bool => $job->full === true,
    );
});

it('dispatches stale snapshot refresh jobs in bounded batches', function (): void {
    Queue::fake();

    CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'meta_synced_at' => now()->subDays(3),
        'transactions_synced_at' => now()->subDays(3),
        'summary_synced_at' => now()->subDays(3),
    ]);

    CompanySnapshot::factory()->create([
        'netsuite_company_id' => 287,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    Artisan::call('company-snapshots:refresh-stale', ['--limit' => 1]);

    Queue::assertPushed(SyncCompanySnapshotMeta::class, 1);
});
