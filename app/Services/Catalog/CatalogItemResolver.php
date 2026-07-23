<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\CatalogItemAlias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CatalogItemResolver
{
    public function __construct(
        private readonly CatalogItemNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, string>|null  $statuses
     * @return array{item: CatalogItem, matched_by: string, requested: string, normalized: string, matched_value: string|null, alias: CatalogItemAlias|null}|null
     */
    public function resolve(string $partNumber, ?array $statuses = [CatalogItem::STATUS_ACTIVE, CatalogItem::STATUS_DISCONTINUED]): ?array
    {
        $normalized = $this->normalizer->normalize($partNumber);

        if ($normalized === '') {
            return null;
        }

        $directQuery = CatalogItem::query()
            ->where('normalized_item_number', $normalized);
        $this->constrainStatuses($directQuery, $statuses);

        $item = $directQuery->first();

        if ($item instanceof CatalogItem) {
            return [
                'item' => $item,
                'matched_by' => 'item_number',
                'requested' => $partNumber,
                'normalized' => $normalized,
                'matched_value' => $item->item_number,
                'alias' => null,
            ];
        }

        $aliasQuery = CatalogItemAlias::query()
            ->with('catalogItem')
            ->where('normalized_alias', $normalized)
            ->orderByDesc('confidence')
            ->orderBy('id');

        if ($statuses !== null) {
            $aliasQuery->whereHas('catalogItem', function (Builder $query) use ($statuses): void {
                $this->constrainStatuses($query, $statuses);
            });
        }

        $alias = $aliasQuery->first();

        if (! $alias instanceof CatalogItemAlias || ! $alias->catalogItem instanceof CatalogItem) {
            return null;
        }

        return [
            'item' => $alias->catalogItem,
            'matched_by' => $alias->type,
            'requested' => $partNumber,
            'normalized' => $normalized,
            'matched_value' => $alias->alias,
            'alias' => $alias,
        ];
    }

    /**
     * @param  array<int, string>|null  $statuses
     * @return Collection<int, CatalogItem>
     */
    public function suggest(string $partNumber, int $limit = 5, ?array $statuses = [CatalogItem::STATUS_ACTIVE, CatalogItem::STATUS_DISCONTINUED]): Collection
    {
        $normalized = $this->normalizer->normalize($partNumber);

        if (strlen($normalized) < 2) {
            return collect();
        }

        $items = CatalogItem::query()
            ->where(function (Builder $query) use ($normalized): void {
                $query->where('normalized_item_number', 'like', $normalized.'%')
                    ->orWhere('normalized_item_number', 'like', '%'.$normalized.'%');
            });
        $this->constrainStatuses($items, $statuses);

        $matches = $items
            ->orderByRaw('CASE WHEN normalized_item_number LIKE ? THEN 0 ELSE 1 END', [$normalized.'%'])
            ->orderBy('item_number')
            ->limit($limit)
            ->get();

        if ($matches->count() >= $limit) {
            return $matches;
        }

        $seen = $matches->pluck('id')->all();
        $aliases = CatalogItemAlias::query()
            ->with('catalogItem')
            ->where(function (Builder $query) use ($normalized): void {
                $query->where('normalized_alias', 'like', $normalized.'%')
                    ->orWhere('normalized_alias', 'like', '%'.$normalized.'%');
            })
            ->when($seen !== [], fn (Builder $query) => $query->whereNotIn('catalog_item_id', $seen))
            ->when($statuses !== null, function (Builder $query) use ($statuses): void {
                $query->whereHas('catalogItem', function (Builder $query) use ($statuses): void {
                    $this->constrainStatuses($query, $statuses);
                });
            })
            ->orderByDesc('confidence')
            ->orderBy('alias')
            ->limit($limit - $matches->count())
            ->get()
            ->pluck('catalogItem')
            ->filter(fn (?CatalogItem $item): bool => $item instanceof CatalogItem)
            ->values();

        return $matches
            ->concat($aliases)
            ->unique('id')
            ->values();
    }

    /**
     * @param  Builder<CatalogItem>  $query
     * @param  array<int, string>|null  $statuses
     */
    private function constrainStatuses(Builder $query, ?array $statuses): void
    {
        if ($statuses === null) {
            return;
        }

        $query->whereIn('status', $statuses);
    }
}
