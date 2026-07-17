<?php

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotDatabaseManager;
use App\Services\CompanySnapshots\CompanySnapshotTransactionRelationshipRepository;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    config([
        'company-snapshots.path' => storage_path('framework/testing/company-snapshots'),
    ]);

    File::deleteDirectory(storage_path('framework/testing/company-snapshots'));
});

it('finds invoices and credit memos related to a sales order through transaction links', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'transactions_synced_at' => now(),
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
            'netsuite_id' => 9002,
            'tranid' => 'INV1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'CustInvc',
            'status' => 'B',
            'trandate' => '2026-01-04',
            'total' => '1250.50',
            'foreign_total' => '1250.50',
            'currency' => 'USD',
            'memo' => 'Invoice',
            'last_modified_at' => '2026-01-04 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'netsuite_id' => 9003,
            'tranid' => 'CM1001',
            'other_ref_num' => 'PO-1001',
            'type' => 'CustCred',
            'status' => 'B',
            'trandate' => '2026-01-05',
            'total' => '-100.00',
            'foreign_total' => '-100.00',
            'currency' => 'USD',
            'memo' => 'Credit memo',
            'last_modified_at' => '2026-01-05 12:00:00',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $connection->table('transaction_links')->insert([
        [
            'link_key' => '9001:1:9002:1:OrdBill',
            'previous_transaction_netsuite_id' => 9001,
            'previous_line_id' => '1',
            'previous_transaction_type' => 'SalesOrd',
            'previous_transaction_number' => 'SO1001',
            'previous_last_modified_at' => '2026-01-03 12:00:00',
            'next_transaction_netsuite_id' => 9002,
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
            'link_key' => '9002:1:9003:1:Credit',
            'previous_transaction_netsuite_id' => 9002,
            'previous_line_id' => '1',
            'previous_transaction_type' => 'CustInvc',
            'previous_transaction_number' => 'INV1001',
            'previous_last_modified_at' => '2026-01-04 12:00:00',
            'next_transaction_netsuite_id' => 9003,
            'next_line_id' => '1',
            'next_transaction_type' => 'CustCred',
            'next_transaction_number' => 'CM1001',
            'next_last_modified_at' => '2026-01-05 12:00:00',
            'link_type' => 'Credit',
            'raw_payload' => '{}',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $repository = app(CompanySnapshotTransactionRelationshipRepository::class);

    expect($repository->linksForTransaction($snapshot, 9001))->toHaveCount(1)
        ->and($repository->financialDocumentsForSalesOrder($snapshot, 9001)->pluck('tranid')->all())->toBe(['CM1001', 'INV1001'])
        ->and($repository->invoicesForSalesOrder($snapshot, 9001)->pluck('tranid')->all())->toBe(['INV1001'])
        ->and($repository->creditMemosForSalesOrder($snapshot, 9001)->pluck('tranid')->all())->toBe(['CM1001']);
});
