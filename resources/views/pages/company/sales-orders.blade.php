<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>


<x-pages::company.layout>
    <div class="flex flex-row justify-between items-center w-full mb-3">
        <div class="flex flex-col">
            <flux:heading size="lg">{{ __('Sales Orders') }}</flux:heading>
            <flux:text>{{ __('Orders currently submitted and processed by :company.', ['company' => config('app.name')]) }}</flux:text>
        </div>
        <small class="italic text-zinc-600 dark:text-zinc-400">{{ __('Synced :time', ['time' => 'diff for humans']) }}</small>
    </div>
    <flux:table class="w-full">
        <flux:table.columns sticky class="bg-white dark:bg-zinc-900">
            <flux:table.column sortable>{{ __('Sales Order #') }}</flux:table.column>
            <flux:table.column sortable>{{ __('Date') }}</flux:table.column>
            <flux:table.column sortable>{{ __('Status') }}</flux:table.column>
            <flux:table.column sortable>{{ __('Total') }}</flux:table.column>
            <flux:table.column>{{ __('Last Modified') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            <flux:table.row>
                <flux:table.cell>1</flux:table.cell>
                <flux:table.cell>2023-01-01</flux:table.cell>
                <flux:table.cell>Processing</flux:table.cell>
                <flux:table.cell>$100.00</flux:table.cell>
                <flux:table.cell>2023-01-01</flux:table.cell>
                <flux:table.cell class="py-0">
                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"></flux:button>
                </flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>
</x-pages::company.layout>
