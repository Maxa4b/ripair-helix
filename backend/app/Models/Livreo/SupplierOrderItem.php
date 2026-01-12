<?php

namespace App\Models\Livreo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOrderItem extends Model
{
    protected $table = 'livreo_supplier_order_items';

    protected $fillable = [
        'supplier_order_id',
        'order_item_id',
        'product_variant_id',
        'supplier_sku',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
    ];

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }
}

