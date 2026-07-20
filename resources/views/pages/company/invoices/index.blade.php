<?php

use App\Services\CompanySnapshots\CompanySnapshotSyncer;
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
        $this->netsuiteCompanyId = (int) $company;

        abort_if($this->netsuiteCompanyId <= 0, 404);

        $snapshot = $this->snapshotSyncer->ensureSnapshot($this->netsuiteCompanyId);

        $this->snapshotId = $snapshot->id;
        $this->snapshotSyncer->queueRefreshIfStale($snapshot, transactionStaleDays: 1);
    }
};
?>

<section class="w-full">
    <x-pages::company.layout :company="$netsuiteCompanyId">
        <livewire:components::company-invoices-table :snapshot-id="$snapshotId" />
    </x-pages::company.layout>
</section>
