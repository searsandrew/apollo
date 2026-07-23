<?php

use App\Jobs\SyncCatalogItems;
use App\Models\CatalogItem;
use App\Models\CatalogItemAlias;
use App\Models\CatalogItemPrice;
use App\Models\CatalogSyncRun;
use App\Services\Catalog\CatalogItemResolver;
use App\Services\Catalog\CatalogSyncer;
use App\Services\NetSuite\NetSuiteCatalogItemRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    Http::preventStrayRequests();
});

it('syncs catalog items with aliases and prices into local tables', function (): void {
    $counts = app(CatalogSyncer::class)->syncPage([
        [
            'netsuite_item_id' => 123,
            'item_number' => 'WB31X5013CM',
            'display_name' => '6 inch ring',
            'description' => '6" Ring',
            'status' => CatalogItem::STATUS_ACTIVE,
            'is_inactive' => false,
            'is_discontinued' => false,
            'multiple' => 1,
            'available_quantity' => 42,
            'availability_status' => 'in_stock',
            'last_modified_at' => '2026-07-21 12:00:00',
            'aliases' => [
                [
                    'alias' => 'DW4001',
                    'type' => CatalogItemAlias::TYPE_CROSS_REFERENCE,
                    'source' => 'legacy',
                    'confidence' => 95,
                ],
            ],
            'prices' => [
                [
                    'price_level' => 'Base Price',
                    'minimum_quantity' => 0,
                    'price' => '3.59',
                    'currency' => 'USD',
                ],
            ],
            'raw_payload' => ['id' => '123'],
        ],
        [
            'netsuite_item_id' => 456,
            'item_number' => null,
        ],
    ], Carbon::parse('2026-07-23 10:00:00'));

    $item = CatalogItem::query()->firstWhere('item_number', 'WB31X5013CM');

    expect($counts)->toBe([
        'items_seen' => 2,
        'items_upserted' => 1,
        'aliases_upserted' => 2,
        'prices_upserted' => 1,
    ])
        ->and($item)->toBeInstanceOf(CatalogItem::class)
        ->and($item?->normalized_item_number)->toBe('WB31X5013CM')
        ->and($item?->available_quantity)->toBe(42)
        ->and($item?->aliases()->where('normalized_alias', 'DW4001')->exists())->toBeTrue()
        ->and($item?->prices()->first()?->price)->toBe('3.59')
        ->and(CatalogItem::query()->count())->toBe(1)
        ->and(CatalogItemAlias::query()->count())->toBe(2)
        ->and(CatalogItemPrice::query()->count())->toBe(1);

    app(CatalogSyncer::class)->syncPage([
        [
            'netsuite_item_id' => 123,
            'item_number' => 'WB31-X5013CM',
            'description' => 'Updated description',
            'prices' => [
                [
                    'price_level' => 'Base Price',
                    'minimum_quantity' => 0,
                    'price' => '4.25',
                    'currency' => 'USD',
                ],
            ],
        ],
    ]);

    expect(CatalogItem::query()->count())->toBe(1)
        ->and(CatalogItem::query()->first()?->description)->toBe('Updated description')
        ->and(CatalogItem::query()->first()?->normalized_item_number)->toBe('WB31X5013CM')
        ->and(CatalogItemPrice::query()->count())->toBe(1)
        ->and(CatalogItemPrice::query()->first()?->price)->toBe('4.25');
});

it('resolves typed part numbers by normalized item number or alias', function (): void {
    $activeItem = CatalogItem::factory()->create([
        'item_number' => 'WB31X5013CM',
        'normalized_item_number' => 'WB31X5013CM',
    ]);
    CatalogItemAlias::factory()->create([
        'catalog_item_id' => $activeItem->id,
        'alias' => 'DW4001',
        'normalized_alias' => 'DW4001',
        'type' => CatalogItemAlias::TYPE_CROSS_REFERENCE,
        'confidence' => 95,
    ]);
    CatalogItem::factory()->create([
        'item_number' => 'OLDPART',
        'normalized_item_number' => 'OLDPART',
        'status' => CatalogItem::STATUS_INACTIVE,
        'is_inactive' => true,
    ]);
    $discontinuedItem = CatalogItem::factory()->create([
        'item_number' => 'NLA123',
        'normalized_item_number' => 'NLA123',
        'status' => CatalogItem::STATUS_DISCONTINUED,
        'is_discontinued' => true,
    ]);

    $resolver = app(CatalogItemResolver::class);

    expect($resolver->resolve(' wb31-x5013cm ')['item']->is($activeItem))->toBeTrue()
        ->and($resolver->resolve('dw-4001')['matched_by'])->toBe(CatalogItemAlias::TYPE_CROSS_REFERENCE)
        ->and($resolver->resolve('OLDPART'))->toBeNull()
        ->and($resolver->resolve('OLDPART', null)['item']->item_number)->toBe('OLDPART')
        ->and($resolver->resolve('NLA-123')['item']->is($discontinuedItem))->toBeTrue()
        ->and($resolver->suggest('dw4')->first()?->is($activeItem))->toBeTrue();
});

it('maps catalog item pages from the upstream datasource', function (): void {
    Http::fake(fn (Request $request) => Http::response([
        'items' => [
            [
                'id' => '123',
                'itemid' => 'WB31X5013CM',
                'displayname' => '6 inch ring',
                'description' => '6" Ring',
                'isinactive' => 'F',
                'lastmodifieddate' => '2026-07-21T12:34:56',
            ],
            [
                'id' => '456',
                'itemid' => '',
                'displayname' => 'Skipped',
            ],
        ],
        'hasMore' => false,
    ]));

    $page = app(NetSuiteCatalogItemRepository::class)
        ->fetchItemPage(25, 50, Carbon::parse('2026-07-01 10:00:00'));

    expect($page['has_more'])->toBeFalse()
        ->and($page['items'])->toHaveCount(1)
        ->and($page['items'][0]['netsuite_item_id'])->toBe(123)
        ->and($page['items'][0]['item_number'])->toBe('WB31X5013CM')
        ->and($page['items'][0]['status'])->toBe(CatalogItem::STATUS_ACTIVE);

    Http::assertSent(function (Request $request): bool {
        $query = $request->data()['q'] ?? '';

        return str_contains($request->url(), '/services/rest/query/v1/suiteql')
            && str_contains($request->url(), 'limit=25')
            && str_contains($request->url(), 'offset=50')
            && str_contains($query, 'FROM item')
            && str_contains($query, "lastmodifieddate >= TO_DATE('2026-07-01 10:00:00'")
            && str_contains($query, 'ORDER BY lastmodifieddate ASC, id ASC');
    });
});

it('dispatches catalog sync jobs from the command', function (): void {
    Queue::fake();

    Artisan::call('catalog:sync-items', [
        '--since' => '2026-07-01 10:00:00',
        '--limit' => 50,
        '--pages' => 2,
    ]);

    Queue::assertPushed(SyncCatalogItems::class, function (SyncCatalogItems $job): bool {
        return $job->modifiedSince === '2026-07-01 10:00:00'
            && $job->limit === 50
            && $job->maxPages === 2
            && $job->type === CatalogSyncRun::TYPE_INCREMENTAL;
    });
});
