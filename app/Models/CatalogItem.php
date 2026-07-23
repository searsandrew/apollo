<?php

namespace App\Models;

use Database\Factories\CatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'netsuite_item_id',
    'item_number',
    'normalized_item_number',
    'display_name',
    'description',
    'status',
    'is_inactive',
    'is_discontinued',
    'multiple',
    'available_quantity',
    'availability_status',
    'last_synced_at',
    'raw_payload',
])]
class CatalogItem extends Model
{
    /** @use HasFactory<CatalogItemFactory> */
    use HasFactory;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_DISCONTINUED = 'discontinued';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'netsuite_item_id' => 'integer',
            'is_inactive' => 'boolean',
            'is_discontinued' => 'boolean',
            'multiple' => 'integer',
            'available_quantity' => 'integer',
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CatalogItemAlias::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CatalogItemPrice::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function isAvailableForOrdering(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ! $this->is_inactive
            && ! $this->is_discontinued;
    }
}
