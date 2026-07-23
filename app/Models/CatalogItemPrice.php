<?php

namespace App\Models;

use Database\Factories\CatalogItemPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_item_id',
    'price_level',
    'minimum_quantity',
    'price',
    'currency',
    'starts_at',
    'ends_at',
    'last_synced_at',
    'raw_payload',
])]
class CatalogItemPrice extends Model
{
    /** @use HasFactory<CatalogItemPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'catalog_item_id' => 'integer',
            'minimum_quantity' => 'integer',
            'price' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
