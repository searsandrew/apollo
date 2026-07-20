<?php

use App\Services\CompanySnapshots\CompanySnapshotSyncer;
use Livewire\Component;

new class extends Component {
    public int $netsuiteCompanyId;

    public int $snapshotId;

    public int $transactionId;

    private CompanySnapshotSyncer $snapshotSyncer;

    public function boot(CompanySnapshotSyncer $snapshotSyncer): void
    {
        $this->snapshotSyncer = $snapshotSyncer;
    }

    public function mount(string $company, string $transaction): void
    {
        $this->netsuiteCompanyId = (int) $company;
        abort_if($this->netsuiteCompanyId <= 0, 404);

        $this->transactionId = (int) $transaction;
        abort_if($this->transactionId <= 0, 404);

        $snapshot = $this->snapshotSyncer->ensureSnapshot($this->netsuiteCompanyId);

        $this->snapshotId = $snapshot->id;
        $this->snapshotSyncer->queueRefreshIfStale($snapshot, transactionStaleDays: 1);
    }
};
?>

<section class="w-full">
    <x-pages::company.layout :company="$netsuiteCompanyId">
        <livewire:components::company-transaction-detail
            :snapshot-id="$snapshotId"
            :transaction-id="$transactionId"
            :types="['SalesOrd']"
            document-label="{{ __('Sales Order') }}"
            number-label="{{ __('Order #') }}"
        />
    </x-pages::company.layout>
</section>
