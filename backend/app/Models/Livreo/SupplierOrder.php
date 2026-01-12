<?php

namespace App\Models\Livreo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierOrder extends Model
{
    protected $table = 'livreo_supplier_orders';

    protected $fillable = [
        'order_id',
        'supplier',
        'supplier_order_number',
        'supplier_carrier',
        'supplier_tracking_number',
        'supplier_tracking_url',
        'supplier_shipped_at',
        'supplier_email_message_id',
        'supplier_email_subject',
        'supplier_email_from',
        'supplier_email_received_at',
        'status',
        'ordered_at',
        'received_at',
        'invoice_path',
        'notes',
    ];

    protected $casts = [
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
        'supplier_shipped_at' => 'datetime',
        'supplier_email_received_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class, 'supplier_order_id');
    }
}
