<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<div>
    <input type="search" autofocus wire:model.debounce.300ms="search" id="laravel-livewire-modals" class="transform-all rounded-full shrink w-80 h-8 px-5 text-light italic shadow-inner border border-gray-200 bg-gray-50 hover:bg-white" placeholder="Part number, type, or OEM...">
</div>
