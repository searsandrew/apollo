<?php

use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $netsuiteCompanyId;
    public int $snapshotId;

    public function mount(string $company): void
    {
        $this->netsuiteCompanyId = (int)$company;
        abort_if($this->netsuiteCompanyId <= 0, 404);

        $snapshot = CompanySnapshotSyncer::ensureSnapshot($this->netsuiteCompanyId);
        $this->snapshotId = $snapshot->id;
    }

    #[Computed]
    public function companyHeaderReady(): bool
    {
        return filled($this->summary?->company_name)
            && filled($this->summary?->account_number);
    }
};
?>

<div class="relative mb-6 w-full">
    @if (! $this->companyHeaderReady())
        <div wire:poll.visible.1500ms>
            <flux:heading size="xl" level="1">
                <flux:skeleton.line animate="shimmer" class="h-10 w-full sm:w-1/3"/>
            </flux:heading>

            <flux:subheading size="lg" class="mb-6">
                <flux:skeleton.line animate="shimmer" class="w-1/2 sm:w-1/5"/>
            </flux:subheading>
        </div>
    @else
        <flux:heading size="xl" level="1">{{ $this->summary->company_name }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $this->summary->account_number }}</flux:subheading>
    @endif

    <flux:separator variant="subtle"/>
</div>
