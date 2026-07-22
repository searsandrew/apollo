<?php

namespace App\Models;

use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'order_id',
    'part_number',
    'description',
    'notes',
    'quantity',
    'unit_price',
    'amount',
    'availability_status',
    'netsuite_item_id',
    'position',
])]
class OrderLine extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<OrderLineFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $auditInclude = [
        'order_id',
        'part_number',
        'description',
        'notes',
        'quantity',
        'unit_price',
        'amount',
        'availability_status',
        'netsuite_item_id',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'netsuite_item_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<int, string>
     */
    public function generateTags(): array
    {
        return [
            'order-line',
            'order:'.$this->order_id,
        ];
    }
}
