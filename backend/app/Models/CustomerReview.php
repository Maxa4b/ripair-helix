<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerReview extends Model
{
    use HasFactory;

    protected $table = 'customer_reviews';

    protected $fillable = [
        'rating',
        'comment',
        'first_name',
        'last_name',
        'show_name',
        'status',
        'moderated_at',
        'moderated_by',
        'admin_note',
        'ip_hash',
        'user_agent',
        'source_page',
    ];

    protected $casts = [
        'rating' => 'integer',
        'show_name' => 'boolean',
        'moderated_at' => 'datetime',
    ];
}

