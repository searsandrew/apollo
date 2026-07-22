<?php

use App\Models\Order;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\Auth;
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

    public function render()
    {
        return $this->view()->title(__('Order #:id', ['id' => $this->order->id]));
    }
};
?>

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Order #:id', ['id' => $order->id]) }}</flux:heading>
                <flux:text>
                    {{ $order->companySummary?->company_name ?? __('Company #:id', ['id' => $order->netsuite_company_id]) }}
                </flux:text>
            </div>

            <flux:badge color="amber" size="sm">{{ __(str($order->status)->headline()->toString()) }}</flux:badge>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <div class="space-y-6">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input
                        wire:model.live.debounce.750ms="poNumber"
                        :label="__('PO Number')"
                        :placeholder="__('PO Number')"
                    />

                    <flux:textarea
                        wire:model.live.debounce.750ms="remarks"
                        :label="__('Memo')"
                        :placeholder="__('Optional notes for this order')"
                        rows="2"
                    />
                </div>

                <form wire:submit="addLine" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_8rem_auto] md:items-end">
                    <flux:input
                        wire:model="partNumber"
                        :label="__('Part Number')"
                        :placeholder="__('Add part')"
                        autocomplete="off"
                    />

                    <flux:input
                        wire:model="quantity"
                        :label="__('Quantity')"
                        type="number"
                        min="1"
                        step="1"
                    />

                    <flux:button type="submit" variant="primary" icon="plus">
                        {{ __('Add Item') }}
                    </flux:button>
                </form>

                <flux:table class="w-full">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Part Number') }}</flux:table.column>
                        <flux:table.column class="w-full">{{ __('Description') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Subtotal') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($order->lines as $line)
                            <flux:table.row wire:key="order-line-{{ $line->id }}">
                                <flux:table.cell>{{ $line->part_number }}</flux:table.cell>
                                <flux:table.cell>
                                    <span class="text-zinc-500 dark:text-zinc-400">
                                        {{ $line->description ?? __('Pending lookup') }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $line->quantity }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    {{ $line->unit_price === null ? '-' : Number::currency((float) $line->unit_price) }}
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    {{ $line->amount === null ? '-' : Number::currency((float) $line->amount) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5">
                                    <div class="py-10 text-center">
                                        <flux:heading size="sm">{{ __('No items yet') }}</flux:heading>
                                        <flux:text>{{ __('Add part numbers as you build the order. Each line saves immediately.') }}</flux:text>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('Summary') }}</flux:heading>

                <div class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Items') }}</span>
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $order->lines->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</span>
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ Number::currency($this->subtotal) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</span>
                        <flux:badge color="amber" size="sm">{{ __(str($order->status)->headline()->toString()) }}</flux:badge>
                    </div>
                </div>

                <flux:separator class="my-4"/>

                <flux:text class="text-xs">
                    {{ __('PO number, memo, and entered items are saved while the order is being built.') }}
                </flux:text>
            </div>
        </div>
    </div>
</section>
