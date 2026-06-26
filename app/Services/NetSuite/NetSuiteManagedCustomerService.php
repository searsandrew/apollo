<?php

namespace App\Services\NetSuite;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Searsandrew\BriarRose\BriarRoseManager;
use Throwable;

class NetSuiteManagedCustomerService
{
    private const int CLOSED_WON_CUSTOMER_STATUS_ID = 13;

    private const int CACHE_SECONDS = 900;

    private const int PAGE_LIMIT = 1000;

    public function __construct(
        private readonly BriarRoseManager $briarRose,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @return array<int, array{id: int, account_number: string|null, name: string, email: string|null}>
     */
    public function allForUser(User $user): array
    {
        $salesRepIds = $this->normalizedManagedSalesRepIds($user->getMeta('netsuite_managed_ids'));

        if ($salesRepIds === []) {
            return [];
        }

        return $this->rememberCustomers(
            user: $user,
            salesRepIds: $salesRepIds,
            search: null,
            limit: null,
        );
    }

    /**
     * @return array<int, array{id: int, account_number: string|null, name: string, email: string|null}>
     */
    public function searchForUser(User $user, string $search, int $limit = 15): array
    {
        $salesRepIds = $this->normalizedManagedSalesRepIds($user->getMeta('netsuite_managed_ids'));

        if ($salesRepIds === []) {
            return [];
        }

        $search = $this->normalizedCustomerSearch($search);

        if ($search === '') {
            return [];
        }

        return $this->rememberCustomers(
            user: $user,
            salesRepIds: $salesRepIds,
            search: $search,
            limit: $limit,
        );
    }

    /**
     * @param  array<int, int>  $salesRepIds
     * @return array<int, array{id: int, account_number: string|null, name: string, email: string|null}>
     */
    private function rememberCustomers(User $user, array $salesRepIds, ?string $search, ?int $limit): array
    {
        try {
            return $this->cache->remember(
                $this->customerCacheKey($user, $salesRepIds, $search, $limit),
                self::CACHE_SECONDS,
                fn (): array => $this->fetchCustomersFromNetSuite($salesRepIds, $search, $limit),
            );
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  array<int, int>  $salesRepIds
     * @return array<int, array{id: int, account_number: string|null, name: string, email: string|null}>
     */
    private function fetchCustomersFromNetSuite(array $salesRepIds, ?string $search, ?int $limit): array
    {
        $customers = [];
        $offset = 0;
        $pageLimit = $limit ?? self::PAGE_LIMIT;

        do {
            $page = $this->briarRose->rest()->suiteql()->query(
                $this->customerSuiteQl($salesRepIds, $search),
                ['limit' => $pageLimit, 'offset' => $offset],
            )->throw()->json();

            foreach ($page['items'] ?? [] as $customer) {
                $customers[] = [
                    'id' => (int) $customer['id'],
                    'account_number' => blank($customer['account_number'] ?? null) ? null : (string) $customer['account_number'],
                    'name' => $customer['companyname'] ?: $customer['entityid'] ?: 'Customer '.$customer['id'],
                    'email' => $customer['email'] ?? null,
                ];
            }

            $offset += $pageLimit;
        } while ($limit === null && ($page['hasMore'] ?? false) === true);

        return $customers;
    }

    /**
     * @return array<int, int>
     */
    private function normalizedManagedSalesRepIds(mixed $managedSalesRepIds): array
    {
        if (! is_array($managedSalesRepIds)) {
            return [];
        }

        return collect($managedSalesRepIds)
            ->map(fn (mixed $salesRepId): int => (int) $salesRepId)
            ->filter(fn (int $salesRepId): bool => $salesRepId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $salesRepIds
     */
    private function customerSuiteQl(array $salesRepIds, ?string $search): string
    {
        $salesRepIdsSql = implode(', ', $salesRepIds);
        $closedWonCustomerStatusId = self::CLOSED_WON_CUSTOMER_STATUS_ID;
        $searchSql = $this->customerSearchSql($search);

        return <<<SQL
            SELECT
                id,
                entityid,
                custentity3 AS account_number,
                companyname,
                email
            FROM customer
            WHERE isinactive = 'F'
                AND entitystatus = {$closedWonCustomerStatusId}
                AND salesrep IN ({$salesRepIdsSql})
                {$searchSql}
            ORDER BY companyname ASC, entityid ASC
        SQL;
    }

    private function normalizedCustomerSearch(string $search): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $search));
    }

    private function customerSearchSql(?string $search): string
    {
        if ($search === null || $search === '') {
            return '';
        }

        $searchTerm = $this->suiteQlStringLiteral('%'.$search.'%');

        return <<<SQL
            AND (
                UPPER(companyname) LIKE UPPER({$searchTerm})
                OR UPPER(entityid) LIKE UPPER({$searchTerm})
                OR UPPER(custentity3) LIKE UPPER({$searchTerm})
            )
        SQL;
    }

    private function suiteQlStringLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param  array<int, int>  $salesRepIds
     */
    private function customerCacheKey(User $user, array $salesRepIds, ?string $search, ?int $limit): string
    {
        return 'netsuite-managed-customers:'.($user->getKey() ?? 'guest').':'.md5((string) json_encode([
            'sales_rep_ids' => $salesRepIds,
            'search' => $search,
            'limit' => $limit,
        ]));
    }
}
