<?php

use App\Models\CompanySnapshot;
use App\Models\CompanySummary;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    config([
        'company-snapshots.path' => storage_path('framework/testing/company-snapshots'),
    ]);

    File::deleteDirectory(storage_path('framework/testing/company-snapshots'));
});

it('renders the company header from the summary', function (): void {
    $snapshot = CompanySnapshot::factory()->create([
        'netsuite_company_id' => 286,
        'status' => CompanySnapshot::STATUS_ACTIVE,
        'meta_synced_at' => now(),
        'transactions_synced_at' => now(),
        'summary_synced_at' => now(),
    ]);

    CompanySummary::factory()->create([
        'company_snapshot_id' => $snapshot->id,
        'netsuite_company_id' => $snapshot->netsuite_company_id,
        'company_name' => 'Acme Industrial',
        'account_number' => 'A-0121',
    ]);

    Permission::findOrCreate('view company');

    $user = User::factory()->create();
    $user->givePermissionTo('view company');

    $this->actingAs($user)
        ->get(route('company.show', 286))
        ->assertOk()
        ->assertSee('Acme Industrial')
        ->assertSee('A-0121')
        ->assertSee('data-current="data-current"', false);
});
