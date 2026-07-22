<?php

use App\Models\CompanySnapshot;
use App\Services\CompanySnapshots\CompanySnapshotInvoiceRepository;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use App\Services\CompanySnapshots\CompanySnapshotTransactionRelationshipRepository;
use App\Services\NetSuite\NetSuiteTransactionStatusMapper;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private const int TRANSACTION_STALE_DAYS = 1;

    public int $snapshotId;

    public string $sortBy = CompanySnapshotInvoiceRepository::DEFAULT_SORT_BY;

    public string $sortDirection = CompanySnapshotInvoiceRepository::DEFAULT_SORT_DIRECTION;

    public string $search = '';

    public string $related = '';

    public string $source = '';

    public ?string $visibleRefreshRequestedAt = null;

    private CompanySnapshotInvoiceRepository $invoiceRepository;

    private CompanySnapshotTransactionRelationshipRepository $relationshipRepository;

    private CompanySnapshotSyncer $snapshotSyncer;

    private NetSuiteTransactionStatusMapper $statusMapper;

    public function boot(
        CompanySnapshotInvoiceRepository $invoiceRepository,
        CompanySnapshotTransactionRelationshipRepository $relationshipRepository,
        CompanySnapshotSyncer $snapshotSyncer,
        NetSuiteTransactionStatusMapper $statusMapper,
    ): void {
        $this->invoiceRepository = $invoiceRepository;
        $this->relationshipRepository = $relationshipRepository;
        $this->snapshotSyncer = $snapshotSyncer;
        $this->statusMapper = $statusMapper;
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function queryString(): array
    {
        return [
            'search' => ['as' => 'q', 'except' => ''],
            'related' => ['except' => ''],
            'source' => ['except' => ''],
        ];
    }

    #[Computed]
    public function snapshot(): CompanySnapshot
    {
        return CompanySnapshot::query()->findOrFail($this->snapshotId);
    }

    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        return $this->invoiceRepository->paginate(
            snapshot: $this->snapshot,
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            search: $this->search,
            netsuiteIds: $this->relatedIds(),
        );
    }

    #[Computed]
    public function shouldPoll(): bool
    {
        return $this->snapshot->transactions_synced_at === null
            || $this->visibleRefreshRequestedAt !== null
            || $this->isSyncing()
            || $this->snapshot->isMetaStale()
            || $this->snapshot->areTransactionsStale(self::TRANSACTION_STALE_DAYS);
    }

    public function syncStartedAt(): ?CarbonInterface
    {
        if ($this->snapshot->transactions_synced_at === null) {
            return $this->snapshot->created_at;
        }

        return $this->snapshot->updated_at;
    }

    public function refreshSyncState(): void
    {
        $this->clearTransactionMemo();
        $this->resolveVisibleRefreshState();

        if ($this->visibleRefreshRequestedAt !== null) {
            return;
        }

        if (! $this->shouldPoll || $this->isSyncing()) {
            return;
        }

        if (! Cache::add($this->refreshQueueCacheKey(), true, now()->addMinute())) {
            return;
        }

        $this->snapshotSyncer->queueRefreshIfStale(
            $this->snapshot,
            transactionStaleDays: self::TRANSACTION_STALE_DAYS,
        );
    }

    public function refreshVisibleRecords(): void
    {
        $invoices = $this->invoices;
        $transactionIds = $this->visibleTransactionIds();

        if ($transactionIds === []) {
            return;
        }

        $this->visibleRefreshRequestedAt = now()->toIso8601String();

        $this->snapshotSyncer->queueTransactionBatchRefresh(
            $this->snapshot,
            $transactionIds,
            queueFollowUpRefresh: $invoices->total() > count($transactionIds),
        );

        $this->clearTransactionMemo();
    }

    public function isRefreshingVisibleRecords(): bool
    {
        return $this->visibleRefreshRequestedAt !== null || $this->isTransactionSyncing();
    }

    public function isSyncing(): bool
    {
        return in_array($this->snapshot->status, [
            CompanySnapshot::STATUS_SYNCING_META,
            CompanySnapshot::STATUS_SYNCING_TRANSACTIONS,
        ], true);
    }

    public function sort(string $column): void
    {
        if (! $this->invoiceRepository->isSortable($column)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = $this->invoiceRepository->defaultDirectionFor($column);
        }

        $this->resetPage(pageName: CompanySnapshotInvoiceRepository::PAGE_NAME);
    }

    public function updatedSearch(): void
    {
        unset($this->invoices);

        $this->resetPage(pageName: CompanySnapshotInvoiceRepository::PAGE_NAME);
    }

    public function updatedRelated(): void
    {
        unset($this->invoices);

        $this->resetPage(pageName: CompanySnapshotInvoiceRepository::PAGE_NAME);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->related = '';
        $this->source = '';

        unset($this->invoices);

        $this->resetPage(pageName: CompanySnapshotInvoiceRepository::PAGE_NAME);
    }

    public function hasFilters(): bool
    {
        return trim($this->search) !== '' || $this->relatedIds() !== [];
    }

    /**
     * @return array<int, int>
     */
    public function relatedIds(): array
    {
        return collect(explode(',', $this->related))
            ->map(fn (string $id): string => trim($id))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function relatedSalesOrdersUrl(int $netsuiteTransactionId, string $documentNumber): ?string
    {
        $salesOrders = $this->relationshipRepository->salesOrdersForTransaction($this->snapshot, $netsuiteTransactionId);

        if ($salesOrders->isEmpty()) {
            return null;
        }

        return route('company.sales-orders.index', [
            'company' => $this->snapshot->netsuite_company_id,
            'related' => $salesOrders->pluck('netsuite_id')->implode(','),
            'source' => $documentNumber,
        ]);
    }

    public function transactionTypeLabel(?string $type): string
    {
        return $this->statusMapper->typeLabel($type);
    }

    public function transactionTypeColor(?string $type): string
    {
        return $this->statusMapper->typeColor($type);
    }

    public function transactionStatusLabel(?string $type, ?string $status): string
    {
        return $this->statusMapper->label($type, $status);
    }

    public function transactionStatusColor(?string $type, ?string $status): string
    {
        return $this->statusMapper->color($type, $status);
    }

    public function currencyCode(mixed $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        if ($currency === '' || $currency === '1' || str_contains($currency, 'US DOLLAR')) {
            return 'USD';
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            return $currency;
        }

        return 'USD';
    }

    private function refreshQueueCacheKey(): string
    {
        return 'company-snapshot:refresh-queued:'.$this->snapshotId;
    }

    private function isTransactionSyncing(): bool
    {
        return $this->snapshot->status === CompanySnapshot::STATUS_SYNCING_TRANSACTIONS;
    }

    private function resolveVisibleRefreshState(): void
    {
        if ($this->visibleRefreshRequestedAt === null) {
            return;
        }

        $requestedAt = Carbon::parse($this->visibleRefreshRequestedAt);

        if ($requestedAt->lt(now()->subMinutes(10))) {
            $this->visibleRefreshRequestedAt = null;

            return;
        }

        if ($this->isSyncing()) {
            return;
        }

        $visibleRows = collect($this->invoices->items());

        if ($visibleRows->isNotEmpty() && $visibleRows->every(fn (object $transaction): bool => filled($transaction->synced_at) && Carbon::parse((string) $transaction->synced_at)->gte($requestedAt))) {
            $this->visibleRefreshRequestedAt = null;
        }
    }

    /**
     * @return array<int, int>
     */
    private function visibleTransactionIds(): array
    {
        return collect($this->invoices->items())
            ->pluck('netsuite_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function clearTransactionMemo(): void
    {
        unset($this->snapshot, $this->invoices);
    }
};
?>

<div @if ($this->shouldPoll) wire:poll.visible.5s="refreshSyncState" @endif>
    <div class="mb-3 flex w-full flex-row items-center justify-between gap-4">
        <div class="flex flex-col">
            <flux:heading size="lg">{{ __('Invoices') }}</flux:heading>
            <flux:text>{{ __('Invoices and credits currently processed by :company.', ['company' => config('app.name')]) }}</flux:text>
        </div>

        <div class="flex shrink-0 flex-col items-end gap-1">
            @php($transactionsSyncedAt = $this->snapshot->transactions_synced_at)
            @php($isRefreshingVisibleRecords = $this->isRefreshingVisibleRecords())

            @if ($transactionsSyncedAt !== null)
                @if ($isRefreshingVisibleRecords)
                    <span class="animate-pulse text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Refreshing records') }}</span>
                @else
                    <button
                        type="button"
                        wire:key="invoices-synced-at-{{ $transactionsSyncedAt->getTimestamp() }}"
                        wire:click="refreshVisibleRecords"
                        wire:loading.attr="disabled"
                        wire:target="refreshVisibleRecords"
                        class="cursor-pointer text-xs text-zinc-500 underline-offset-4 hover:text-zinc-800 hover:underline disabled:cursor-wait disabled:opacity-70 dark:text-zinc-400 dark:hover:text-zinc-100"
                        title="{{ __('Refresh visible records from the data source') }}"
                        aria-live="polite"
                        x-data="relativeTime({
                            timestamp: @js($transactionsSyncedAt->toIso8601String()),
                            fallback: @js($transactionsSyncedAt->diffForHumans()),
                        })"
                    >
                        <span wire:loading.remove wire:target="refreshVisibleRecords">{{ __('Synced') }} <span x-text="label">{{ $transactionsSyncedAt->diffForHumans() }}</span></span>
                        <span wire:loading wire:target="refreshVisibleRecords">{{ __('Queueing refresh') }}</span>
                    </button>
                @endif
            @else
                <small class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Waiting for first sync') }}</small>
            @endif
        </div>
    </div>

    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:input class="sm:max-w-sm" size="sm" icon="magnifying-glass" clearable wire:model.live.debounce.300ms="search" placeholder="{{ __('Search invoice, credit memo, or PO number') }}" />

        @if ($this->hasFilters())
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->relatedIds() !== [])
                    <flux:badge size="sm" color="zinc">{{ __('Related to :source', ['source' => $source ?: __('selected record')]) }}</flux:badge>
                @endif

                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            </div>
        @endif
    </div>

    @if ($this->invoices->count() === 0)
        @if ($this->shouldPoll && ! $this->hasFilters())
            @php($syncStartedAt = $this->syncStartedAt())

            <div class="rounded-lg border border-dashed border-sky-200 bg-sky-50/40 p-6 dark:border-sky-900/70 dark:bg-sky-950/20">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300">
                        <flux:icon.loading class="size-5" />
                    </div>

                    <div class="min-w-0">
                        <flux:heading size="sm">{{ __('Invoices are syncing') }}</flux:heading>
                        <flux:text>{{ __('We are checking our servers. This page will update automatically as soon as invoices are available.') }}</flux:text>

                        <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400" aria-live="polite">
                            <span wire:loading.remove>{{ __('Waiting for next check') }}</span>
                            <span wire:loading>{{ __('Checking now') }}</span>

                            @if ($syncStartedAt !== null)
                                <span aria-hidden="true">&middot;</span>
                                <span
                                    x-data="relativeTime({
                                        timestamp: @js($syncStartedAt->toIso8601String()),
                                        fallback: @js($syncStartedAt->diffForHumans()),
                                    })"
                                >
                                    {{ __('Started') }} <span x-text="label">{{ $syncStartedAt->diffForHumans() }}</span>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="rounded-lg border border-dashed border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="sm">{{ $this->hasFilters() ? __('No matching invoices found') : __('No invoices found') }}</flux:heading>
                <flux:text>{{ $this->hasFilters() ? __('No invoices or credits matched the current search or relationship filter.') : __('No invoices or credits were found in the company data source.') }}</flux:text>
            </div>
        @endif
    @else
        <flux:table class="w-full" :paginate="$this->invoices">
            <flux:table.columns sticky class="bg-white dark:bg-zinc-900">
                <flux:table.column sortable class="w-40" :sorted="$sortBy === 'invoice_number'" :direction="$sortDirection" wire:click="sort('invoice_number')">{{ __('Invoice/Credit #') }}</flux:table.column>
                <flux:table.column sortable class="w-52" :sorted="$sortBy === 'po_number'" :direction="$sortDirection" wire:click="sort('po_number')">{{ __('PO Number') }}</flux:table.column>
                <flux:table.column sortable class="w-32" :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">{{ __('Date') }}</flux:table.column>
                <flux:table.column sortable align="center" class="w-32" :sorted="$sortBy === 'type'" :direction="$sortDirection" wire:click="sort('type')">{{ __('Type') }}</flux:table.column>
                <flux:table.column sortable align="center" class="w-40" :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">{{ __('Status') }}</flux:table.column>
                <flux:table.column sortable class="w-28" :sorted="$sortBy === 'total'" :direction="$sortDirection" wire:click="sort('total')">{{ __('Total') }}</flux:table.column>
                <flux:table.column align="end" class="w-12"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->invoices as $invoice)
                    @php($invoiceNumber = $invoice->tranid ?: $invoice->netsuite_id)
                    @php($poNumber = $invoice->other_ref_num ?: '-')
                    @php($typeLabel = $this->transactionTypeLabel($invoice->type))
                    @php($statusLabel = $this->transactionStatusLabel($invoice->type, $invoice->status))
                    @php($relatedSalesOrdersUrl = $this->relatedSalesOrdersUrl((int) $invoice->netsuite_id, (string) $invoiceNumber))
                    @php($documentUrl = route($invoice->type === 'CustCred' ? 'company.credit-memos.show' : 'company.invoices.show', [$this->snapshot->netsuite_company_id, $invoice->netsuite_id]))

                    <flux:table.row
                        wire:key="invoice-{{ $invoice->netsuite_id }}"
                        class="cursor-pointer transition-colors hover:bg-zinc-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-white/5"
                        role="link"
                        tabindex="0"
                        data-href="{{ $documentUrl }}"
                        aria-label="{{ $invoice->type === 'CustCred' ? __('View credit memo :number', ['number' => $invoiceNumber]) : __('View invoice :number', ['number' => $invoiceNumber]) }}"
                        x-on:click="Livewire.navigate($el.dataset.href)"
                        x-on:keydown.enter.prevent="Livewire.navigate($el.dataset.href)"
                        x-on:keydown.space.prevent="Livewire.navigate($el.dataset.href)"
                    >
                        <flux:table.cell class="w-40 font-medium">
                            <span class="block truncate" title="{{ $invoiceNumber }}">{{ $invoiceNumber }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="w-52">
                            <span class="block truncate" title="{{ $poNumber }}">{{ $poNumber }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ blank($invoice->trandate) ? '-' : Carbon::parse((string) $invoice->trandate)->toFormattedDateString() }}</flux:table.cell>
                        <flux:table.cell align="center" class="w-32">
                            <flux:badge size="sm" class="max-w-28" :color="$this->transactionTypeColor($invoice->type)" inset="top bottom" title="{{ $typeLabel }}">
                                <span class="block truncate">{{ $typeLabel }}</span>
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="center" class="w-40">
                            <flux:badge size="sm" class="max-w-36" :color="$this->transactionStatusColor($invoice->type, $invoice->status)" inset="top bottom" title="{{ $statusLabel }}">
                                <span class="block truncate">{{ $statusLabel }}</span>
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ Number::currency((float) $invoice->total, in: $this->currencyCode($invoice->currency)) }}</flux:table.cell>
                        <flux:table.cell align="end" class="w-12 py-0" x-on:click.stop x-on:keydown.stop>
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="{{ __('Billing document actions') }}"></flux:button>
                                <flux:menu>
                                    @can('view invoice')
                                        <flux:menu.item icon="document-currency-dollar" :href="$documentUrl" wire:navigate>{{ $invoice->type === 'CustCred' ? __('View Credit Memo') : __('View Invoice') }}</flux:menu.item>
                                    @endcan
                                    @can('view order')
                                        @if ($relatedSalesOrdersUrl !== null)
                                            <flux:menu.item icon="document-magnifying-glass" :href="$relatedSalesOrdersUrl" wire:navigate>{{ __('Show Sales Orders') }}</flux:menu.item>
                                        @endif
                                    @endcan
                                    @can('create return')
                                        <flux:menu.item icon="receipt-refund">{{ __('Return Good Authorization') }}</flux:menu.item>
                                    @endcan
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
