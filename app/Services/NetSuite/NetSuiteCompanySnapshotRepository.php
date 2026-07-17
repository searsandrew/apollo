<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Carbon;
use Searsandrew\BriarRose\BriarRoseManager;
use Throwable;

class NetSuiteCompanySnapshotRepository
{
    public function __construct(
        private readonly BriarRoseManager $briarRose,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fetchMeta(int $netsuiteCompanyId): ?array
    {
        $sql = <<<SQL
            SELECT
                id,
                entityid,
                custentity3 AS account_number,
                companyname,
                BUILTIN.DF(terms) AS terms,
                email,
                phone,
                url,
                isinactive,
                entitystatus,
                salesrep,
                datecreated,
                lastmodifieddate
            FROM customer
            WHERE id = {$netsuiteCompanyId}
        SQL;

        $page = $this->briarRose->rest()->suiteql()->query($sql, [
            'limit' => 1,
            'offset' => 0,
        ])->throw()->json();

        $company = $page['items'][0] ?? null;

        if (! is_array($company)) {
            return null;
        }

        return [
            'netsuite_company_id' => (int) $company['id'],
            'entity_id' => $this->nullableString($company['entityid'] ?? null),
            'account_number' => $this->nullableString($company['account_number'] ?? null),
            'company_name' => $this->nullableString($company['companyname'] ?? null),
            'terms' => $this->nullableString($company['terms'] ?? null),
            'email' => $this->nullableString($company['email'] ?? null),
            'phone' => $this->nullableString($company['phone'] ?? null),
            'url' => $this->nullableString($company['url'] ?? null),
            'is_inactive' => ($company['isinactive'] ?? null) === 'T',
            'entity_status' => $this->nullableString($company['entitystatus'] ?? null),
            'sales_rep_id' => $this->nullableInt($company['salesrep'] ?? null),
            'date_created' => $this->nullableString($company['datecreated'] ?? null),
            'last_modified_at' => $this->nullableString($company['lastmodifieddate'] ?? null),
            'raw_payload' => $company,
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchTransactionPage(int $netsuiteCompanyId, int $limit = 1000, int $offset = 0, ?string $modifiedSince = null): array
    {
        $modifiedSinceClause = $this->modifiedSinceClause('lastmodifieddate', $modifiedSince);

        $sql = <<<SQL
            SELECT
                id,
                tranid,
                otherrefnum,
                type,
                trandate,
                status,
                memo,
                total,
                foreigntotal,
                currency,
                lastmodifieddate
            FROM transaction
            WHERE entity = {$netsuiteCompanyId}
            {$modifiedSinceClause}
            ORDER BY trandate DESC, id DESC
        SQL;

        $page = $this->briarRose->rest()->suiteql()->query($sql, [
            'limit' => $limit,
            'offset' => $offset,
        ])->throw()->json();

        $transactions = [];

        foreach ($page['items'] ?? [] as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $transactions[] = [
                'netsuite_id' => (int) $transaction['id'],
                'tranid' => $this->nullableString($transaction['tranid'] ?? null),
                'other_ref_num' => $this->nullableString($transaction['otherrefnum'] ?? null),
                'type' => $this->nullableString($transaction['type'] ?? null),
                'trandate' => $this->nullableString($transaction['trandate'] ?? null),
                'status' => $this->nullableString($transaction['status'] ?? null),
                'memo' => $this->nullableString($transaction['memo'] ?? null),
                'total' => $this->decimalString($transaction['total'] ?? null),
                'foreign_total' => $this->decimalString($transaction['foreigntotal'] ?? null),
                'currency' => $this->nullableString($transaction['currency'] ?? null),
                'last_modified_at' => $this->nullableDateTimeString($transaction['lastmodifieddate'] ?? null),
                'raw_payload' => $transaction,
            ];
        }

        return [
            'items' => $transactions,
            'has_more' => (bool) ($page['hasMore'] ?? false),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchTransactionLinePage(int $netsuiteCompanyId, int $limit = 1000, int $offset = 0, ?string $transactionModifiedSince = null): array
    {
        $modifiedSinceClause = $this->modifiedSinceClause('transaction.lastmodifieddate', $transactionModifiedSince);

        $sql = <<<SQL
            SELECT
                transactionline.transaction AS transaction_id,
                transactionline.id AS line_id,
                transactionline.item,
                item.itemid AS item_name,
                transactionline.quantity,
                transactionline.rate,
                transactionline.netamount AS amount,
                transactionline.memo
            FROM transactionline
            LEFT JOIN item ON item.id = transactionline.item
            JOIN transaction ON transaction.id = transactionline.transaction
            WHERE transaction.entity = {$netsuiteCompanyId}
            {$modifiedSinceClause}
            ORDER BY transaction.trandate DESC, transaction.id DESC, transactionline.id
        SQL;

        $page = $this->briarRose->rest()->suiteql()->query($sql, [
            'limit' => $limit,
            'offset' => $offset,
        ])->throw()->json();

        $lines = [];

        foreach ($page['items'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            if (($line['transaction_id'] ?? null) === null || ($line['line_id'] ?? null) === null) {
                continue;
            }

            $lines[] = [
                'transaction_netsuite_id' => (int) $line['transaction_id'],
                'line_id' => $this->nullableString($line['line_id'] ?? null),
                'item_id' => $this->nullableInt($line['item'] ?? null),
                'item_name' => $this->nullableString($line['item_name'] ?? null),
                'quantity' => $this->decimalString($line['quantity'] ?? null, 4),
                'rate' => $this->decimalString($line['rate'] ?? null, 4),
                'amount' => $this->decimalString($line['amount'] ?? null),
                'memo' => $this->nullableString($line['memo'] ?? null),
                'raw_payload' => $line,
            ];
        }

        return [
            'items' => $lines,
            'has_more' => (bool) ($page['hasMore'] ?? false),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchTransactionLinkPage(int $netsuiteCompanyId, int $limit = 1000, int $offset = 0, ?string $transactionModifiedSince = null): array
    {
        $modifiedSinceClause = $this->transactionLinkModifiedSinceClause($transactionModifiedSince);

        $sql = <<<SQL
            SELECT
                nextTransactionLineLink.previousDoc AS previous_doc,
                nextTransactionLineLink.previousLine AS previous_line,
                nextTransactionLineLink.nextDoc AS next_doc,
                nextTransactionLineLink.nextLine AS next_line,
                nextTransactionLineLink.linkType AS link_type,
                previousTransaction.type AS previous_type,
                previousTransaction.tranid AS previous_tranid,
                previousTransaction.lastmodifieddate AS previous_lastmodifieddate,
                nextTransaction.type AS next_type,
                nextTransaction.tranid AS next_tranid,
                nextTransaction.lastmodifieddate AS next_lastmodifieddate
            FROM nextTransactionLineLink
            JOIN transaction previousTransaction ON previousTransaction.id = nextTransactionLineLink.previousDoc
            JOIN transaction nextTransaction ON nextTransaction.id = nextTransactionLineLink.nextDoc
            WHERE (previousTransaction.entity = {$netsuiteCompanyId} OR nextTransaction.entity = {$netsuiteCompanyId})
            {$modifiedSinceClause}
            ORDER BY previousTransaction.trandate DESC, previousTransaction.id DESC, nextTransaction.id DESC, nextTransactionLineLink.previousLine, nextTransactionLineLink.nextLine
        SQL;

        $page = $this->briarRose->rest()->suiteql()->query($sql, [
            'limit' => $limit,
            'offset' => $offset,
        ])->throw()->json();

        $links = [];

        foreach ($page['items'] ?? [] as $link) {
            if (! is_array($link)) {
                continue;
            }

            if (($link['previous_doc'] ?? null) === null || ($link['next_doc'] ?? null) === null) {
                continue;
            }

            $links[] = [
                'previous_transaction_netsuite_id' => (int) $link['previous_doc'],
                'previous_line_id' => $this->nullableString($link['previous_line'] ?? null),
                'previous_transaction_type' => $this->nullableString($link['previous_type'] ?? null),
                'previous_transaction_number' => $this->nullableString($link['previous_tranid'] ?? null),
                'previous_last_modified_at' => $this->nullableDateTimeString($link['previous_lastmodifieddate'] ?? null),
                'next_transaction_netsuite_id' => (int) $link['next_doc'],
                'next_line_id' => $this->nullableString($link['next_line'] ?? null),
                'next_transaction_type' => $this->nullableString($link['next_type'] ?? null),
                'next_transaction_number' => $this->nullableString($link['next_tranid'] ?? null),
                'next_last_modified_at' => $this->nullableDateTimeString($link['next_lastmodifieddate'] ?? null),
                'link_type' => $this->nullableString($link['link_type'] ?? null),
                'raw_payload' => $link,
            ];
        }

        return [
            'items' => $links,
            'has_more' => (bool) ($page['hasMore'] ?? false),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function nullableDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function modifiedSinceClause(string $field, ?string $modifiedSince): string
    {
        if ($modifiedSince === null || $modifiedSince === '') {
            return '';
        }

        $modifiedSince = Carbon::parse($modifiedSince)->format('Y-m-d H:i:s');

        return "AND {$field} >= TO_DATE('{$modifiedSince}', 'yyyy-mm-dd hh24:mi:ss')";
    }

    private function transactionLinkModifiedSinceClause(?string $modifiedSince): string
    {
        if ($modifiedSince === null || $modifiedSince === '') {
            return '';
        }

        $modifiedSince = Carbon::parse($modifiedSince)->format('Y-m-d H:i:s');

        return "AND (
                previousTransaction.lastmodifieddate >= TO_DATE('{$modifiedSince}', 'yyyy-mm-dd hh24:mi:ss')
                OR nextTransaction.lastmodifieddate >= TO_DATE('{$modifiedSince}', 'yyyy-mm-dd hh24:mi:ss')
            )";
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function decimalString(mixed $value, int $precision = 2): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $precision, '.', '');
        }

        return number_format((float) $value, $precision, '.', '');
    }
}
