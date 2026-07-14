<?php

use App\Models\CompanySummary;
use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $netsuiteCompanyId;
    public int $snapshotId;

    private CompanySnapshotSyncer $snapshotSyncer;

    public function boot(CompanySnapshotSyncer $snapshotSyncer): void
    {
        $this->snapshotSyncer = $snapshotSyncer;
    }

    public function mount(string $company): void
    {
        $this->netsuiteCompanyId = (int)$company;
        abort_if($this->netsuiteCompanyId <= 0, 404);

        $snapshot = $this->snapshotSyncer->ensureSnapshot($this->netsuiteCompanyId);
        $this->snapshotId = $snapshot->id;
    }

    #[Computed]
    public function summary(): ?CompanySummary
    {
        return CompanySummary::query()
            ->where('company_snapshot_id', $this->snapshotId)
            ->first();
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
    @if (! $this->companyHeaderReady)
        <div wire:poll.visible.2s>
            <flux:heading size="xl" level="1">
                <flux:skeleton.line animate="shimmer" class="h-10 w-full sm:w-1/3"/>
            </flux:heading>

            <flux:subheading size="lg" class="mb-6">
                <flux:skeleton.line animate="shimmer" class="w-1/2 sm:w-1/5"/>
            </flux:subheading>
        </div>
    @else
        <flux:heading size="xl" level="1">{{ $this->summary->company_name }}</flux:heading>
        <flux:subheading size="lg" class="mb-6"><flux:badge>{{ $this->summary->account_number }}</flux:badge></flux:subheading>
    @endif

    <flux:separator variant="subtle"/>
</div>
