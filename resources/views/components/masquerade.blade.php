<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Searsandrew\BriarRose\Facades\BriarRose;

new class extends Component
{
    private const int CLOSED_WON_CUSTOMER_STATUS_ID = 13;

    private const int SEARCH_CUSTOMER_LIMIT = 15;

    private const int CUSTOMER_CACHE_SECONDS = 900;

    /**
     * @var array<int, array{id: int, account_number: string|null, name: string, email: string|null}>
     */
    public array $customers = [];

    public ?int $netSuiteId = null;

    public bool $hasSearchedCustomers = false;

    public string $customerSearch = '';

    public function mount(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $this->netSuiteId = $user->netsuite_user_id === null ? null : (int) $user->netsuite_user_id;
    }

    public function selectCustomer(int $netSuiteId): Redirector
    {
        $user = Auth::user();

        if ($user !== null) {
            $this->netSuiteId = $netSuiteId;
            $user->setMeta('customer_id', $netSuiteId);
            $user->save();
        }

        return redirect()->route('dashboard');
    }

    public function searchCustomers(string $search): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $this->customerSearch = $this->normalizedCustomerSearch($search);
        $this->hasSearchedCustomers = mb_strlen($this->customerSearch) >= 2;

        if (! $this->hasSearchedCustomers) {
            $this->customers = [];

            return;
        }

        $this->customers = $this->customersForManagedSalesReps(
            $user->getMeta('netsuite_managed_ids'),
            $this->customerSearch,
        );
    }

    /**
     * @return array<int, array{id: int, account_number: string|null, name: string, email: string|null}>
     */
    private function customersForManagedSalesReps(mixed $managedSalesRepIds, string $search): array
    {
        $salesRepIds = $this->normalizedManagedSalesRepIds($managedSalesRepIds);

        if ($salesRepIds === []) {
            return [];
        }

        $search = $this->normalizedCustomerSearch($search);

        try {
            return Cache::remember(
                $this->customerCacheKey($salesRepIds, $search),
                self::CUSTOMER_CACHE_SECONDS,
                fn (): array => $this->fetchCustomersFromNetSuite($salesRepIds, $search),
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
    private function fetchCustomersFromNetSuite(array $salesRepIds, string $search): array
    {
        $salesRepIdsSql = implode(', ', $salesRepIds);
        $closedWonCustomerStatusId = self::CLOSED_WON_CUSTOMER_STATUS_ID;
        $searchSql = $this->customerSearchSql($search);
        $sql = <<<SQL
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

        $page = BriarRose::rest()->suiteql()->query($sql, [
            'limit' => self::SEARCH_CUSTOMER_LIMIT,
            'offset' => 0,
        ])->throw()->json();

        $customers = [];

        foreach ($page['items'] ?? [] as $customer) {
            $customers[] = [
                'id' => (int) $customer['id'],
                'account_number' => blank($customer['account_number'] ?? null) ? null : (string) $customer['account_number'],
                'name' => $customer['companyname'] ?: $customer['entityid'] ?: 'Customer '.$customer['id'],
                'email' => $customer['email'] ?? null,
            ];
        }

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
    private function customerCacheKey(array $salesRepIds, string $search): string
    {
        return 'masquerade-customers:'.(Auth::id() ?? 'guest').':'.md5(implode(',', $salesRepIds).'|'.$search);
    }

    private function normalizedCustomerSearch(string $search): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $search));
    }

    private function customerSearchSql(string $search): string
    {
        if ($search === '') {
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
};
?>

<flux:tooltip :content="__('Masquerade')" position="bottom" gap="10">
    <flux:dropdown
        position="bottom"
        align="end"
        x-data="{
            open: false,
            search: '',
            activeIndex: -1,
            focusSearch() {
                this.$nextTick(() => setTimeout(() => this.$refs.customerSearch?.focus(), 50))
            },
            toggleOpen() {
                this.open = ! this.open

                if (this.open) {
                    this.focusSearch()
                }
            },
            searchCustomers() {
                this.activeIndex = -1
                this.$wire.searchCustomers(this.search)
            },
            customerItems() {
                return Array.from(this.$root.querySelectorAll('[data-masquerade-customer]'))
            },
            moveActive(direction) {
                const items = this.customerItems()

                if (items.length === 0) {
                    this.activeIndex = -1

                    return
                }

                if (this.activeIndex === -1) {
                    this.activeIndex = direction > 0 ? 0 : items.length - 1
                } else {
                    this.activeIndex = (this.activeIndex + direction + items.length) % items.length
                }

                this.$nextTick(() => items[this.activeIndex]?.scrollIntoView({ block: 'nearest' }))
            },
            selectActive() {
                this.customerItems()[this.activeIndex]?.click()
            },
        }"
        x-on:click.outside="open = false"
        x-on:keydown.escape.window="open = false"
        x-on:keydown.window="
            if (open) {
                if ($event.key === 'ArrowDown') {
                    $event.preventDefault()
                    moveActive(1)
                }

                if ($event.key === 'ArrowUp') {
                    $event.preventDefault()
                    moveActive(-1)
                }

                if ($event.key === 'Enter') {
                    $event.preventDefault()
                    selectActive()
                }
            }
        "
    >
        <flux:button
            class="h-10 cursor-pointer max-lg:hidden [&>div>svg]:size-5"
            x-bind:class="open ? 'bg-zinc-100 text-zinc-900 dark:bg-white/10 dark:text-white' : ''"
            variant="subtle"
            icon="fa-masks-theater"
            :label="__('Masquerade')"
            x-on:click="toggleOpen()"
        />
        <flux:menu
            class="shrink-0 overflow-hidden origin-top-right transition-[max-height,opacity,transform] duration-200 ease-out"
            style="width: min(20rem, calc(100vw - 1rem));"
        >
            <div class="p-1.5" wire:ignore>
                <flux:input
                    x-ref="customerSearch"
                    x-model="search"
                    x-on:input.debounce.350ms="searchCustomers()"
                    icon="magnifying-glass"
                    clearable
                    loading="searchCustomers"
                    :placeholder="__('Search customers')"
                />
            </div>

            <div
                class="overflow-y-auto transition-all duration-200 ease-out"
                x-bind:class="search.trim().length >= 2 ? 'max-h-80 opacity-100' : 'max-h-0 opacity-0'"
            >
                <div
                    wire:loading.flex
                    wire:target="searchCustomers"
                    class="items-center gap-2 px-2 py-2 text-sm font-medium text-zinc-500 dark:text-zinc-300"
                >
                    <flux:icon.loading variant="mini" class="text-zinc-400"/>
                    {{ __('Searching...') }}
                </div>

                <div wire:loading.remove wire:target="searchCustomers" class="pt-1">
                    @if ($hasSearchedCustomers && $customers === [])
                        <flux:menu.item disabled>{{ __('No customers found') }}</flux:menu.item>
                    @endif

                    @foreach ($customers as $customer)
                        <flux:menu.item
                            class="relative w-full transition-colors"
                            data-masquerade-customer
                            x-bind:data-active="activeIndex === {{ $loop->index }} ? '' : null"
                            x-bind:class="activeIndex === {{ $loop->index }} ? 'bg-sky-50 text-sky-950 dark:bg-sky-400/20 dark:text-sky-50' : ''"
                            x-on:mouseenter="activeIndex = {{ $loop->index }}"
                            wire:key="masquerade-customer-{{ $customer['id'] }}"
                            wire:click="selectCustomer({{ $customer['id'] }})"
                        >
                            <span
                                aria-hidden="true"
                                class="absolute inset-y-1 left-1 w-1 rounded-full bg-sky-500 transition-opacity"
                                x-bind:class="activeIndex === {{ $loop->index }} ? 'opacity-100' : 'opacity-0'"
                            ></span>

                            <div class="flex w-full min-w-0 items-center gap-2 ps-2">
                                @if ($customer['account_number'] !== null)
                                    <flux:badge class="max-w-24 shrink-0 truncate" size="sm" rounded>
                                        {{ $customer['account_number'] }}
                                    </flux:badge>
                                @endif

                                <span class="min-w-0 flex-1 truncate">{{ $customer['name'] }}</span>
                            </div>
                        </flux:menu.item>
                    @endforeach
                </div>
            </div>
        </flux:menu>
    </flux:dropdown>
</flux:tooltip>
