@props([
    'company'
])

<div class="w-full">
    <livewire:components::company-name :company="$company" />
    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-55">
            <flux:navlist aria-label="{{ __('Company') }}">
                <flux:navlist.item :href="route('company.show', $company)" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
                <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Purchases') }}</flux:navlist.item>
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Invoices') }}</flux:navlist.item>
                <flux:navlist.group :heading="__('Marketing')" expandable :expanded="false">
                    <flux:navlist.item href="#">{{ __('Landing Page') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Fliers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Emails') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Customers') }}</flux:navlist.item>
                    <flux:navlist.item href="#">{{ __('Settings') }}</flux:navlist.item>
                </flux:navlist.group>
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Settings') }}</flux:navlist.item>
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>{{ $heading ?? '' }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

            <div class="mt-5 w-full max-w-lg">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
