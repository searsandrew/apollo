<?php

namespace App\Models;

use Database\Factories\CatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'netsuite_item_id',
    'item_number',
    'normalized_item_number',
    'display_name',
    'description',
    'is_inactive',
    'is_discontinued',
    'is_online',
    'multiple',
    'available_quantity',
    'availability_status',
    'last_synced_at',
    'raw_payload',
])]
class CatalogItem extends Model
{
    /** @use HasFactory<CatalogItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'netsuite_item_id' => 'integer',
            'is_inactive' => 'boolean',
            'is_discontinued' => 'boolean',
            'is_online' => 'boolean',
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

    /**
     * @param  Builder<CatalogItem>  $query
     */
    public function scopeAvailableOnline(Builder $query): void
    {
        $query->where('is_online', true);
    }

    public function isAvailableOnline(): bool
    {
        return $this->is_online;
    }

    public function isAvailableForOrdering(): bool
    {
        return ! $this->is_inactive
            && ! $this->is_discontinued
            && $this->isAvailableOnline();
    }
}
