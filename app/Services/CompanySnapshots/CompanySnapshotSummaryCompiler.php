<?php

namespace App\Services\CompanySnapshots;

use App\Models\CompanySnapshot;
use App\Models\CompanySummary;
use Illuminate\Support\Carbon;

class CompanySnapshotSummaryCompiler
{
    public function __construct(
        private readonly CompanySnapshotDatabaseManager $databaseManager,
    ) {}

    public function compile(CompanySnapshot $snapshot): CompanySummary
    {
        $connection = $this->databaseManager->ensureDatabase($snapshot);
        $meta = $connection->table('meta')->pluck('value', 'key')
            ->map(fn (?string $value): mixed => $value === null ? null : json_decode($value, true))
            ->all();

        $transactions = $connection->table('transactions')->get();
        $now = now();
        $startOfYear = Carbon::now()->startOfYear();
        $trailingTwelveMonths = Carbon::now()->subYear();
        $totalsByType = [];

        foreach ($transactions as $transaction) {
            $type = (string) ($transaction->type ?? 'unknown');
            $totalsByType[$type] ??= 0.0;
            $totalsByType[$type] += (float) $transaction->total;
        }

        $invoiceTransactions = $transactions->filter(fn (object $transaction): bool => $this->isInvoiceType($transaction->type));
        $creditTransactions = $transactions->filter(fn (object $transaction): bool => $this->isCreditMemoType($transaction->type));
        $openOrders = $transactions->filter(fn (object $transaction): bool => $this->isOpenSalesOrder($transaction->type, $transaction->status));

        $summary = CompanySummary::query()->updateOrCreate(
            ['netsuite_company_id' => $snapshot->netsuite_company_id],
            [
                'company_snapshot_id' => $snapshot->id,
                'account_number' => $meta['account_number'] ?? null,
                'company_name' => $meta['company_name'] ?? null,
                'entity_id' => $meta['entity_id'] ?? null,
                'sales_rep_id' => $meta['sales_rep_id'] ?? null,
                'last_transaction_date' => $transactions->max('trandate'),
                'ytd_sales' => $this->sumTransactionsSince($invoiceTransactions, $startOfYear),
                'trailing_12_sales' => $this->sumTransactionsSince($invoiceTransactions, $trailingTwelveMonths),
                'open_order_total' => $openOrders->sum(fn (object $transaction): float => (float) $transaction->total),
                'invoice_total' => $invoiceTransactions->sum(fn (object $transaction): float => (float) $transaction->total),
                'credit_memo_total' => $creditTransactions->sum(fn (object $transaction): float => (float) $transaction->total),
                'transaction_count' => $transactions->count(),
                'totals_by_type' => $totalsByType,
                'snapshot_synced_at' => $snapshot->transactions_synced_at ?? $snapshot->meta_synced_at,
                'summary_synced_at' => $now,
            ],
        );

        $snapshot->forceFill(['summary_synced_at' => $now])->save();

        return $summary;
    }

    private function sumTransactionsSince(mixed $transactions, Carbon $date): float
    {
        return $transactions
            ->filter(fn (object $transaction): bool => $transaction->trandate !== null
                && Carbon::parse($transaction->trandate)->greaterThanOrEqualTo($date))
            ->sum(fn (object $transaction): float => (float) $transaction->total);
    }

    private function isInvoiceType(mixed $type): bool
    {
        $type = mb_strtolower((string) $type);

        return str_contains($type, 'invoice') || str_contains($type, 'custinvc');
    }

    private function isCreditMemoType(mixed $type): bool
    {
        $type = mb_strtolower((string) $type);

        return str_contains($type, 'credit') || str_contains($type, 'custcred');
    }

    private function isOpenSalesOrder(mixed $type, mixed $status): bool
    {
        $type = mb_strtolower((string) $type);
        $status = mb_strtolower((string) $status);

        if (! str_contains($type, 'sales') && ! str_contains($type, 'salesord')) {
            return false;
        }

        return ! str_contains($status, 'closed')
            && ! str_contains($status, 'billed')
            && ! str_contains($status, 'cancel');
    }
}
