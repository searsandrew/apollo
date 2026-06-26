<?php

use App\Models\User;
use App\Services\NetSuite\NetSuiteManagedCustomerService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Searsandrew\BriarRose\BriarRoseManager;

beforeEach(function (): void {
    config([
        'briar-rose.account' => '5802217',
        'briar-rose.consumer_key' => 'ck',
        'briar-rose.consumer_secret' => 'cs',
        'briar-rose.token_id' => 'tk',
        'briar-rose.token_secret' => 'ts',
        'briar-rose.rest_base_url' => 'https://5802217.suitetalk.api.netsuite.com',
        'briar-rose.rest.retries.enabled' => false,
    ]);

    app()->forgetInstance(BriarRoseManager::class);
    Cache::flush();
    Http::preventStrayRequests();
});

it('searches managed customers by company, entity id, and account number', function (): void {
    $user = User::factory()->create();
    $user->setMeta('netsuite_managed_ids', [1439, '0', 1427, 1439]);

    Http::fake(fn (Request $request) => Http::response([
        'items' => [
            [
                'id' => '286',
                'entityid' => 'ACME-286',
                'account_number' => 'A-0121',
                'companyname' => 'Acme Industrial',
                'email' => 'ap@example.test',
            ],
        ],
        'hasMore' => false,
    ]));

    $customers = app(NetSuiteManagedCustomerService::class)->searchForUser($user, '  A-0121  ', 15);

    expect($customers)->toBe([
        [
            'id' => 286,
            'account_number' => 'A-0121',
            'name' => 'Acme Industrial',
            'email' => 'ap@example.test',
        ],
    ]);

    Http::assertSent(function (Request $request): bool {
        $query = $request->data()['q'] ?? '';

        return str_contains($request->url(), '/services/rest/query/v1/suiteql')
            && str_contains($request->url(), 'limit=15')
            && str_contains($request->url(), 'offset=0')
            && str_contains($query, "isinactive = 'F'")
            && str_contains($query, 'entitystatus = 13')
            && str_contains($query, 'salesrep IN (1439, 1427)')
            && str_contains($query, "UPPER(companyname) LIKE UPPER('%A-0121%')")
            && str_contains($query, "UPPER(entityid) LIKE UPPER('%A-0121%')")
            && str_contains($query, "UPPER(custentity3) LIKE UPPER('%A-0121%')");
    });
});

it('paginates the default managed customer list', function (): void {
    $user = User::factory()->create();
    $user->setMeta('netsuite_managed_ids', [1439]);

    Http::fake([
        '*' => Http::sequence()
            ->push([
                'items' => [
                    [
                        'id' => '286',
                        'entityid' => 'ACME-286',
                        'account_number' => 'A-0121',
                        'companyname' => 'Acme Industrial',
                        'email' => null,
                    ],
                ],
                'hasMore' => true,
            ])
            ->push([
                'items' => [
                    [
                        'id' => '287',
                        'entityid' => 'BETA-287',
                        'account_number' => null,
                        'companyname' => 'Beta Supply',
                        'email' => 'orders@example.test',
                    ],
                ],
                'hasMore' => false,
            ]),
    ]);

    $customers = app(NetSuiteManagedCustomerService::class)->allForUser($user);

    expect($customers)->toHaveCount(2)
        ->and($customers[0]['name'])->toBe('Acme Industrial')
        ->and($customers[1]['name'])->toBe('Beta Supply');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'limit=1000')
        && str_contains($request->url(), 'offset=1000'));
});

it('caches customer searches for the same user and managed sales reps', function (): void {
    $user = User::factory()->create();
    $user->setMeta('netsuite_managed_ids', [1439]);

    Http::fake(fn (Request $request) => Http::response([
        'items' => [
            [
                'id' => '286',
                'entityid' => 'ACME-286',
                'account_number' => 'A-0121',
                'companyname' => 'Acme Industrial',
                'email' => null,
            ],
        ],
        'hasMore' => false,
    ]));

    $service = app(NetSuiteManagedCustomerService::class);

    expect($service->searchForUser($user, 'Acme'))->toHaveCount(1)
        ->and($service->searchForUser($user, 'Acme'))->toHaveCount(1);

    Http::assertSentCount(1);
});

it('delegates searchable customer lookups from the masquerade component', function (): void {
    $user = User::factory()->create();

    $this->mock(NetSuiteManagedCustomerService::class, function (MockInterface $mock) use ($user): void {
        $mock->shouldReceive('searchForUser')
            ->once()
            ->withArgs(fn (User $managedUser, string $search, int $limit): bool => $managedUser->is($user)
                && $search === 'ACME'
                && $limit === 15)
            ->andReturn([
                [
                    'id' => 286,
                    'account_number' => 'A-0121',
                    'name' => 'Acme Industrial',
                    'email' => null,
                ],
            ]);
    });

    $this->actingAs($user);

    Livewire::test('masquerade')
        ->call('searchCustomers', ' A ')
        ->assertSet('hasSearchedCustomers', false)
        ->assertSet('customers', [])
        ->call('searchCustomers', ' ACME ')
        ->assertSet('hasSearchedCustomers', true)
        ->assertSet('customers.0.name', 'Acme Industrial');
});
