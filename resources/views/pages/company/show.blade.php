<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<section class="w-full">
    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>
    <x-pages::company.layout company="$company">
        Test
    </x-pages::company.layout>
</section>
