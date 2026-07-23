<?php

namespace App\Models;

use Database\Factories\CatalogItemAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_item_id',
    'alias',
    'normalized_alias',
    'type',
    'source',
    'confidence',
    'last_synced_at',
    'raw_payload',
])]
class CatalogItemAlias extends Model
{
    /** @use HasFactory<CatalogItemAliasFactory> */
    use HasFactory;

    public const string TYPE_ITEM_NUMBER = 'item_number';

    public const string TYPE_CROSS_REFERENCE = 'cross_reference';

    public const string TYPE_OEM = 'oem';

    public const string TYPE_SUPERSEDED = 'superseded';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'catalog_item_id' => 'integer',
            'confidence' => 'integer',
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
