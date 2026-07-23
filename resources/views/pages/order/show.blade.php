<?php

use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order')] class extends Component {
    public Order $order;

    public string $poNumber = '';

    public string $remarks = '';

    public string $partNumber = '';

    public int $quantity = 1;

    public function mount(Order $order): void
    {
        abort_unless($order->created_by_user_id === Auth::id(), 403);

        $this->order = $order->load(['companySummary', 'lines']);
        $this->poNumber = (string) $this->order->po_number;
        $this->remarks = (string) $this->order->remarks;
    }

    public function updatedPoNumber(): void
    {
        $this->order->update([
            'po_number' => blank($this->poNumber) ? null : trim($this->poNumber),
        ]);

        $this->order->refresh();
    }

    public function updatedRemarks(): void
    {
        $this->order->update([
            'remarks' => blank($this->remarks) ? null : trim($this->remarks),
        ]);

        $this->order->refresh();
    }

    public function addLine(): void
    {
        $validated = $this->validate([
            'partNumber' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
        ]);

        $this->order->lines()->create([
            'part_number' => trim($validated['partNumber']),
            'quantity' => $validated['quantity'],
            'position' => $this->order->lines()->count() + 1,
        ]);

        $this->reset('partNumber');
        $this->quantity = 1;
        $this->order->refresh()->load(['companySummary', 'lines']);
    }

    #[Computed]
    public function subtotal(): float
    {
        return (float) $this->order->lines->sum(
            fn ($line): float => $line->amount === null ? 0.0 : (float) $line->amount,
        );
    }

    #[Computed]
    public function hasPricing(): bool
    {
        return $this->order->lines->contains(
            fn ($line): bool => $line->unit_price !== null || $line->amount !== null,
        );
    }

    #[Computed]
    public function shipping(): ?float
    {
        if (! $this->hasPricing) {
            return null;
        }

        if ($this->subtotal <= 500) {
            return 19.99;
        }

        if ($this->subtotal < 1000) {
            return 24.99;
        }

        return 0.0;
    }

    #[Computed]
    public function total(): ?float
    {
        return $this->shipping === null ? null : $this->subtotal + $this->shipping;
    }

    #[Computed]
    public function freeShippingProgress(): int
    {
        return min(100, (int) round(($this->subtotal / 1000) * 100));
    }

    #[Computed]
    public function quantityTotal(): int
    {
        return (int) $this->order->lines->sum('quantity');
    }

    public function formattedMoney(?float $amount): string
    {
        return $amount === null ? __('TBD') : Number::currency($amount, in: 'USD');
    }

    public function statusColor(): string
    {
        return match ($this->order->status) {
            Order::STATUS_DRAFT => 'amber',
            Order::STATUS_SUBMITTED, Order::STATUS_PROCESSING => 'sky',
            Order::STATUS_ACCEPTED => 'green',
            Order::STATUS_CANCELLED => 'red',
            default => 'zinc',
        };
    }

    public function render()
    {
        return $this->view()->title(__('Order #:id', ['id' => $this->order->id]));
    }
};
?>

<section class="-mx-4 -my-6 min-h-[calc(100vh-5rem)] bg-zinc-100 text-zinc-950 dark:bg-zinc-950 dark:text-zinc-50 sm:-mx-6 lg:-mx-8">
    <div class="border-b border-zinc-200 bg-white px-4 py-4 dark:border-zinc-800 dark:bg-zinc-900 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="min-w-0">
                <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                    <h1 class="truncate text-xl font-semibold">{{ __('Order') }}</h1>
                    <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Order #:id', ['id' => $order->id]) }}</span>
                    @if ($order->companySummary?->account_number)
                        <flux:badge size="sm" rounded>{{ $order->companySummary->account_number }}</flux:badge>
                    @endif
                </div>
                <div class="mt-1 truncate text-sm text-zinc-600 dark:text-zinc-300">
                    {{ $order->companySummary?->company_name ?? __('Company #:id', ['id' => $order->netsuite_company_id]) }}
                </div>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs text-zinc-500 dark:text-zinc-400" wire:dirty wire:target="poNumber,remarks">{{ __('Unsaved changes') }}</span>
                <span class="text-xs text-zinc-500 dark:text-zinc-400" wire:loading wire:target="updatedPoNumber,updatedRemarks,addLine">{{ __('Saving') }}</span>
                <flux:badge :color="$this->statusColor()" size="sm">{{ __(str($order->status)->headline()->toString()) }}</flux:badge>
            </div>
        </div>
    </div>

    <div class="px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800">
            <div class="grid gap-6 px-4 py-5 md:grid-cols-2 xl:grid-cols-[minmax(12rem,1fr)_minmax(12rem,1fr)_minmax(20rem,24rem)]">
                <div class="min-w-0">
                    <div class="mb-1 text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Bill To') }}</div>
                    <address class="not-italic leading-snug text-zinc-950 dark:text-zinc-50">
                        <div class="font-medium">{{ $order->companySummary?->company_name ?? __('Company #:id', ['id' => $order->netsuite_company_id]) }}</div>
                        <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Billing address pending') }}</div>
                    </address>
                </div>

                <div class="min-w-0">
                    <div class="mb-1 flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Ship To') }}</span>
                        <flux:badge size="sm" color="zinc">{{ __('Change') }}</flux:badge>
                    </div>
                    <address class="not-italic leading-snug text-zinc-950 dark:text-zinc-50">
                        <div class="font-medium">{{ $order->companySummary?->company_name ?? __('Company #:id', ['id' => $order->netsuite_company_id]) }}</div>
                        <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Shipping address pending') }}</div>
                    </address>
                </div>

                <div class="overflow-hidden rounded-md bg-zinc-100 text-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700 md:col-span-2 xl:col-span-1">
                    <div class="bg-zinc-950 py-1 text-center text-xs font-semibold uppercase text-white">{{ __('Summary') }}</div>
                    <div class="grid grid-cols-3 divide-x divide-zinc-200 border-b border-zinc-200 text-center dark:divide-zinc-700 dark:border-zinc-700">
                        <div class="p-3">
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</div>
                            <div class="mt-1 font-medium tabular-nums">{{ $this->formattedMoney($this->hasPricing ? $this->subtotal : null) }}</div>
                        </div>
                        <div class="p-3">
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Shipping') }}</div>
                            <div class="mt-1 font-medium tabular-nums">{{ $this->formattedMoney($this->shipping) }}</div>
                        </div>
                        <div class="p-3">
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</div>
                            <div class="mt-1 font-semibold tabular-nums">{{ $this->formattedMoney($this->total) }}</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 divide-x divide-zinc-200 text-center dark:divide-zinc-700">
                        <label class="col-span-1 p-3">
                            <span class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ __('PO Number') }}</span>
                            <input
                                wire:model.live.debounce.750ms="poNumber"
                                class="h-8 w-full rounded-md border border-zinc-300 bg-white px-2 text-center text-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 dark:border-zinc-700 dark:bg-zinc-900"
                                placeholder="{{ __('PO Number') }}"
                            >
                        </label>
                        <label class="col-span-2 p-3">
                            <span class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ __('Memo') }}</span>
                            <input
                                wire:model.live.debounce.750ms="remarks"
                                class="h-8 w-full rounded-md border border-zinc-300 bg-white px-2 text-center text-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 dark:border-zinc-700 dark:bg-zinc-900"
                                placeholder="{{ __('Optional memo') }}"
                            >
                        </label>
                    </div>
                </div>
            </div>

            <div class="grid border-y border-zinc-200 bg-white text-sm dark:border-zinc-800 dark:bg-zinc-900 md:grid-cols-6">
                <div class="border-b border-zinc-200 px-4 py-3 text-center dark:border-zinc-800 md:border-b-0">
                    <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Order #') }}</div>
                    <div class="mt-1 font-medium">{{ $order->id }}</div>
                </div>
                <div class="border-b border-zinc-200 px-4 py-3 text-center dark:border-zinc-800 md:border-b-0">
                    <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</div>
                    <div class="mt-1">
                        <flux:badge :color="$this->statusColor()" size="sm">{{ __(str($order->status)->headline()->toString()) }}</flux:badge>
                    </div>
                </div>
                <div class="border-b border-zinc-200 px-4 py-3 text-center dark:border-zinc-800 md:border-b-0">
                    <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Terms') }}</div>
                    <div class="mt-1">{{ $order->companySummary?->terms ?? '-' }}</div>
                </div>
                <div class="border-b border-zinc-200 px-4 py-3 text-center dark:border-zinc-800 md:border-b-0">
                    <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Ship Date') }}</div>
                    <div class="mt-1">-</div>
                </div>
                <div class="border-b border-zinc-200 px-4 py-3 text-center dark:border-zinc-800 md:border-b-0">
                    <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Ship Via') }}</div>
                    <div class="mt-1">-</div>
                </div>
                <div class="px-4 py-3 text-center">
                    <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Tracking Number') }}</div>
                    <div class="mt-1">-</div>
                </div>
            </div>

            <div>
                <flux:table container:class="w-full" class="w-full min-w-5xl">
                    <flux:table.columns sticky class="bg-white dark:bg-zinc-900">
                        <flux:table.column class="w-44 ps-4!">{{ __('Part Number') }}</flux:table.column>
                        <flux:table.column>{{ __('Description') }}</flux:table.column>
                        <flux:table.column class="w-40">{{ __('Notes') }}</flux:table.column>
                        <flux:table.column align="center" class="w-28">{{ __('Stock') }}</flux:table.column>
                        <flux:table.column align="end" class="w-24">{{ __('Quantity') }}</flux:table.column>
                        <flux:table.column align="end" class="w-24">{{ __('Price') }}</flux:table.column>
                        <flux:table.column align="end" class="w-28 pe-4!">{{ __('Subtotal') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($order->lines as $line)
                            <flux:table.row wire:key="order-line-{{ $line->id }}" class="odd:bg-white even:bg-zinc-50 dark:odd:bg-zinc-900 dark:even:bg-zinc-900/60">
                                <flux:table.cell class="ps-4! font-medium">{{ $line->part_number }}</flux:table.cell>
                                <flux:table.cell>
                                    <span class="block max-w-lg truncate" title="{{ $line->description ?? __('Pending lookup') }}">
                                        {{ $line->description ?? __('Pending lookup') }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="block max-w-40 truncate text-zinc-600 dark:text-zinc-300" title="{{ $line->notes }}">
                                        {{ $line->notes ?? '-' }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <flux:badge size="sm" color="zinc">{{ $line->availability_status ?? __('Pending') }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $line->quantity }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    {{ $line->unit_price === null ? '-' : Number::currency((float) $line->unit_price, in: 'USD') }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="pe-4!">
                                    {{ $line->amount === null ? '-' : Number::currency((float) $line->amount, in: 'USD') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="7" class="px-4">
                                    <div class="py-16 text-center">
                                        <flux:heading size="sm">{{ __('No items yet') }}</flux:heading>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    </div>

    <div class="sticky bottom-0 z-20 border-t border-zinc-200 bg-white/95 px-4 py-3 shadow-[0_-8px_20px_rgba(15,23,42,0.08)] backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95 sm:px-6 lg:px-8">
        <form wire:submit="addLine" class="grid gap-3 lg:grid-cols-[minmax(12rem,24rem)_8rem_8rem_minmax(14rem,1fr)_10rem] lg:items-center">
            <input
                wire:model="partNumber"
                class="h-9 rounded-full border border-zinc-300 bg-white px-4 text-sm outline-none transition placeholder:text-zinc-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 dark:border-zinc-700 dark:bg-zinc-950"
                placeholder="{{ __('Add Part') }}"
                autocomplete="off"
            >

            <input
                wire:model.number="quantity"
                class="h-9 rounded-full border border-zinc-300 bg-white px-4 text-center text-sm outline-none transition placeholder:text-zinc-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 dark:border-zinc-700 dark:bg-zinc-950"
                type="number"
                min="1"
                step="1"
                placeholder="{{ __('Quantity') }}"
            >

            <flux:button type="submit" variant="primary" icon="plus" class="h-9 rounded-full uppercase" wire:loading.attr="disabled" wire:target="addLine">
                <span wire:loading.remove wire:target="addLine">{{ __('Add Item') }}</span>
                <span wire:loading wire:target="addLine">{{ __('Adding') }}</span>
            </flux:button>

            <div class="min-w-0">
                <div class="mb-1 flex items-center justify-between gap-3 text-xs">
                    <span>{{ __('Free Shipping') }}</span>
                    <span class="font-semibold tabular-nums">{{ $this->freeShippingProgress }}%</span>
                </div>
                <div class="h-2 rounded-full bg-zinc-200 dark:bg-zinc-700">
                    <div class="h-2 rounded-full bg-zinc-600 transition-all dark:bg-zinc-300" style="width: {{ $this->freeShippingProgress }}%"></div>
                </div>
            </div>

            <div class="text-sm">
                <div class="flex justify-between gap-4">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Items') }}</span>
                    <span class="font-medium tabular-nums">{{ $this->quantityTotal }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</span>
                    <span class="font-medium tabular-nums">{{ $this->formattedMoney($this->hasPricing ? $this->subtotal : null) }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</span>
                    <span class="text-lg font-semibold tabular-nums">{{ $this->formattedMoney($this->total) }}</span>
                </div>
            </div>
        </form>
    </div>
</section>
