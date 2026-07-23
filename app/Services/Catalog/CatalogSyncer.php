<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\CatalogItemAlias;
use App\Models\CatalogItemPrice;
use App\Models\CatalogSyncRun;
use App\Services\NetSuite\NetSuiteCatalogItemRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

class CatalogSyncer
{
    public function __construct(
        private readonly CatalogItemNormalizer $normalizer,
        private readonly NetSuiteCatalogItemRepository $netSuite,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items_seen: int, items_upserted: int, aliases_upserted: int, prices_upserted: int}
     */
    public function syncPage(array $items, ?CarbonInterface $syncedAt = null): array
    {
        $syncedAt ??= now();
        $counts = $this->emptyCounts();

        foreach ($items as $payload) {
            $counts['items_seen']++;

            $item = $this->upsertItem($payload, $syncedAt);

            if (! $item instanceof CatalogItem) {
                continue;
            }

            $counts['items_upserted']++;
            $counts['aliases_upserted'] += $this->upsertAliases($item, $payload, $syncedAt);
            $counts['prices_upserted'] += $this->upsertPrices($item, $payload, $syncedAt);
        }

        return $counts;
    }

    public function syncFromNetSuite(
        ?CarbonInterface $modifiedSince = null,
        int $limit = 1000,
        ?int $maxPages = null,
        string $type = CatalogSyncRun::TYPE_INCREMENTAL,
    ): CatalogSyncRun {
        $limit = max(1, $limit);
        $modifiedSince ??= $type === CatalogSyncRun::TYPE_INCREMENTAL
            ? $this->lastSuccessfulCursor()
            : null;

        $run = CatalogSyncRun::start($type, $modifiedSince?->toDateTimeString(), [
            'limit' => $limit,
            'max_pages' => $maxPages,
        ]);

        $offset = 0;
        $pages = 0;
        $counts = $this->emptyCounts();
        $cursor = $modifiedSince?->toDateTimeString();

        try {
            do {
                $page = $this->netSuite->fetchItemPage($limit, $offset, $modifiedSince);
                $pageCounts = $this->syncPage($page['items']);
                $counts = $this->mergeCounts($counts, $pageCounts);
                $cursor = $this->latestCursor($page['items'], $cursor);
                $offset += $limit;
                $pages++;
            } while ($page['has_more'] && ($maxPages === null || $pages < $maxPages));

            $run->markFinished($counts, $cursor);

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->markFailed($exception);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertItem(array $payload, CarbonInterface $syncedAt): ?CatalogItem
    {
        $itemNumber = $this->nullableString($payload['item_number'] ?? null);
        $normalizedItemNumber = $this->normalizer->normalize($itemNumber);

        if ($itemNumber === null || $normalizedItemNumber === '') {
            return null;
        }

        $netsuiteItemId = $this->nullableInt($payload['netsuite_item_id'] ?? null);
        $isInactive = (bool) ($payload['is_inactive'] ?? false);
        $isDiscontinued = (bool) ($payload['is_discontinued'] ?? false);
        $status = $this->statusFromPayload($payload, $isInactive, $isDiscontinued);
        $item = CatalogItem::query()
            ->when($netsuiteItemId !== null, fn ($query) => $query->where('netsuite_item_id', $netsuiteItemId))
            ->orWhere('normalized_item_number', $normalizedItemNumber)
            ->first() ?? new CatalogItem;

        $item->forceFill([
            'netsuite_item_id' => $netsuiteItemId,
            'item_number' => $itemNumber,
            'normalized_item_number' => $normalizedItemNumber,
            'display_name' => $this->nullableString($payload['display_name'] ?? null),
            'description' => $this->nullableString($payload['description'] ?? null),
            'status' => $status,
            'is_inactive' => $isInactive,
            'is_discontinued' => $isDiscontinued,
            'multiple' => $this->nullableInt($payload['multiple'] ?? null),
            'available_quantity' => $this->nullableInt($payload['available_quantity'] ?? null),
            'availability_status' => $this->nullableString($payload['availability_status'] ?? null),
            'last_synced_at' => $syncedAt,
            'raw_payload' => $payload['raw_payload'] ?? $payload,
        ])->save();

        return $item;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertAliases(CatalogItem $item, array $payload, CarbonInterface $syncedAt): int
    {
        $crossReferences = collect($payload['cross_references'] ?? [])
            ->map(fn (mixed $alias): array => [
                'alias' => $alias,
                'type' => CatalogItemAlias::TYPE_CROSS_REFERENCE,
            ]);

        $aliases = collect($payload['aliases'] ?? [])
            ->merge($crossReferences)
            ->prepend([
                'alias' => $payload['item_number'],
                'type' => CatalogItemAlias::TYPE_ITEM_NUMBER,
                'confidence' => 100,
            ]);

        $count = 0;

        foreach ($aliases as $aliasPayload) {
            if (! is_array($aliasPayload)) {
                $aliasPayload = ['alias' => $aliasPayload];
            }

            $alias = $this->nullableString($aliasPayload['alias'] ?? null);
            $normalizedAlias = $this->normalizer->normalize($alias);

            if ($alias === null || $normalizedAlias === '') {
                continue;
            }

            CatalogItemAlias::query()->updateOrCreate(
                [
                    'catalog_item_id' => $item->id,
                    'type' => $this->nullableString($aliasPayload['type'] ?? null) ?? CatalogItemAlias::TYPE_CROSS_REFERENCE,
                    'normalized_alias' => $normalizedAlias,
                ],
                [
                    'alias' => $alias,
                    'source' => $this->nullableString($aliasPayload['source'] ?? null) ?? 'netsuite',
                    'confidence' => $this->nullableInt($aliasPayload['confidence'] ?? null) ?? 90,
                    'last_synced_at' => $syncedAt,
                    'raw_payload' => $aliasPayload,
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertPrices(CatalogItem $item, array $payload, CarbonInterface $syncedAt): int
    {
        $count = 0;

        foreach ($payload['prices'] ?? [] as $pricePayload) {
            if (! is_array($pricePayload)) {
                continue;
            }

            $price = $this->nullableDecimal($pricePayload['price'] ?? null);

            if ($price === null) {
                continue;
            }

            CatalogItemPrice::query()->updateOrCreate(
                [
                    'catalog_item_id' => $item->id,
                    'price_level' => $this->nullableString($pricePayload['price_level'] ?? null) ?? 'Base Price',
                    'minimum_quantity' => $this->nullableInt($pricePayload['minimum_quantity'] ?? null) ?? 0,
                    'currency' => $this->nullableString($pricePayload['currency'] ?? null) ?? 'USD',
                ],
                [
                    'price' => $price,
                    'starts_at' => $pricePayload['starts_at'] ?? null,
                    'ends_at' => $pricePayload['ends_at'] ?? null,
                    'last_synced_at' => $syncedAt,
                    'raw_payload' => $pricePayload,
                ],
            );

            $count++;
        }

        return $count;
    }

    private function lastSuccessfulCursor(): ?Carbon
    {
        $cursor = CatalogSyncRun::query()
            ->where('status', CatalogSyncRun::STATUS_FINISHED)
            ->whereNotNull('cursor_value')
            ->orderByDesc('finished_at')
            ->value('cursor_value');

        return filled($cursor) ? Carbon::parse((string) $cursor) : null;
    }

    /**
     * @return array{items_seen: int, items_upserted: int, aliases_upserted: int, prices_upserted: int}
     */
    private function emptyCounts(): array
    {
        return [
            'items_seen' => 0,
            'items_upserted' => 0,
            'aliases_upserted' => 0,
            'prices_upserted' => 0,
        ];
    }

    /**
     * @param  array{items_seen: int, items_upserted: int, aliases_upserted: int, prices_upserted: int}  $current
     * @param  array{items_seen: int, items_upserted: int, aliases_upserted: int, prices_upserted: int}  $additional
     * @return array{items_seen: int, items_upserted: int, aliases_upserted: int, prices_upserted: int}
     */
    private function mergeCounts(array $current, array $additional): array
    {
        return [
            'items_seen' => $current['items_seen'] + $additional['items_seen'],
            'items_upserted' => $current['items_upserted'] + $additional['items_upserted'],
            'aliases_upserted' => $current['aliases_upserted'] + $additional['aliases_upserted'],
            'prices_upserted' => $current['prices_upserted'] + $additional['prices_upserted'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function latestCursor(array $items, ?string $currentCursor): ?string
    {
        return collect($items)
            ->pluck('last_modified_at')
            ->filter()
            ->map(fn (mixed $lastModifiedAt): string => Carbon::parse((string) $lastModifiedAt)->toDateTimeString())
            ->push($currentCursor)
            ->filter()
            ->max();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function statusFromPayload(array $payload, bool $isInactive, bool $isDiscontinued): string
    {
        $status = $this->nullableString($payload['status'] ?? null);

        if ($status !== null) {
            return $status;
        }

        if ($isInactive) {
            return CatalogItem::STATUS_INACTIVE;
        }

        if ($isDiscontinued) {
            return CatalogItem::STATUS_DISCONTINUED;
        }

        return CatalogItem::STATUS_ACTIVE;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
