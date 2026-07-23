<?php

namespace App\Services\NetSuite;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Searsandrew\BriarRose\BriarRoseManager;
use Throwable;

class NetSuiteCatalogItemRepository
{
    public function __construct(
        private readonly BriarRoseManager $briarRose,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchItemPage(int $limit = 1000, int $offset = 0, ?CarbonInterface $modifiedSince = null): array
    {
        $modifiedSinceClause = $this->modifiedSinceClause('lastmodifieddate', $modifiedSince);

        $sql = <<<SQL
            SELECT
                id,
                itemid,
                displayname,
                description,
                isinactive,
                lastmodifieddate
            FROM item
            WHERE itemid IS NOT NULL
            {$modifiedSinceClause}
            ORDER BY lastmodifieddate ASC, id ASC
        SQL;

        $page = $this->briarRose->rest()->suiteql()->query($sql, [
            'limit' => $limit,
            'offset' => $offset,
        ])->throw()->json();

        $items = [];

        foreach ($page['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $mapped = $this->mapItemRow($item);

            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return [
            'items' => $items,
            'has_more' => (bool) ($page['hasMore'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function mapItemRow(array $item): ?array
    {
        $itemNumber = $this->nullableString($item['itemid'] ?? null);

        if ($itemNumber === null) {
            return null;
        }

        $isInactive = $this->truthyString($item['isinactive'] ?? null);

        return [
            'netsuite_item_id' => $this->nullableInt($item['id'] ?? null),
            'item_number' => $itemNumber,
            'display_name' => $this->nullableString($item['displayname'] ?? null),
            'description' => $this->nullableString($item['description'] ?? null),
            'status' => $isInactive ? 'inactive' : 'active',
            'is_inactive' => $isInactive,
            'is_discontinued' => false,
            'last_modified_at' => $this->nullableDateTimeString($item['lastmodifieddate'] ?? null),
            'aliases' => [],
            'prices' => [],
            'raw_payload' => $item,
        ];
    }

    private function modifiedSinceClause(string $field, ?CarbonInterface $modifiedSince): string
    {
        if (! $modifiedSince instanceof CarbonInterface) {
            return '';
        }

        $modifiedSince = $modifiedSince->format('Y-m-d H:i:s');

        return "AND {$field} >= TO_DATE('{$modifiedSince}', 'yyyy-mm-dd hh24:mi:ss')";
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function nullableDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function truthyString(mixed $value): bool
    {
        return in_array(strtoupper((string) $value), ['T', 'TRUE', '1', 'Y', 'YES'], true);
    }
}
