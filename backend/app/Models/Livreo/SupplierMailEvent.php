<?php

namespace App\Models\Livreo;

use Illuminate\Database\Eloquent\Model;

class SupplierMailEvent extends Model
{
    protected $table = 'livreo_supplier_mail_events';

    protected $fillable = [
        'supplier',
        'message_id',
        'received_at',
        'from_email',
        'subject',
        'supplier_order_number',
        'carrier',
        'tracking_number',
        'matched_supplier_order_id',
        'matched_order_id',
        'preview',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}

