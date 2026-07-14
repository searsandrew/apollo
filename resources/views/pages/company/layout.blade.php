@props([
    'company' => request()->route('company'),
])

@php
    $summary = \App\Models\CompanySummary::where('netsuite_company_id', $company)->first();
@endphp

<div class="w-full">
    <livewire:components::company-name :company="$company" />
    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-55">
            <flux:navlist aria-label="{{ __('Company') }}">
                <flux:navlist.item icon="building-storefront" :href="route('company.show', $company)" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
                <flux:navlist.item icon="clipboard-document-list" :href="route('company.purchase-orders', $company)" wire:navigate>{{ __('Purchase Orders') }}</flux:navlist.item>
                <flux:navlist.item icon="clipboard-document-check" :href="route('company.sales-orders', $company)" wire:navigate>{{ __('Sales Orders') }}</flux:navlist.item>
                <flux:navlist.item :icon="($summary?->terms == 'Credit Card at Time of Purchase' ? 'credit-card' : 'banknotes')" :href="route('appearance.edit')" wire:navigate>{{ __('Invoices') }}</flux:navlist.item>
                <flux:navlist.group :heading="__('Marketing')" expandable :expanded="false">
                    <flux:navlist.item href="#">{{ __('Landing Page') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Fliers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Emails') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Customers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Settings') }}</flux:navlist.item>
                </flux:navlist.group>
                <flux:navlist.item icon="user-group" :href="route('appearance.edit')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                <flux:navlist.item icon="cog-8-tooth" :href="route('appearance.edit')" wire:navigate>{{ __('Settings') }}</flux:navlist.item>
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>{{ $heading ?? '' }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

            <div class="flex flex-col sm:flex-row">
                <div @class(['mt-5 w-full', 'sm:w-2/3 mr-5' => isset($sidebar)])>
                    {{ $slot }}
                </div>
                @isset($sidebar)
                    <sidebar class="order-first sm:order-last w-full sm:max-w-1/3">
                        {{ $sidebar }}
                    </sidebar>
                @endisset
            </div>
        </div>
    </div>
</div>
