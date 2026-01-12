<?php

namespace App\Models\Livreo;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $table = 'livreo_audit_events';

    protected $fillable = [
        'actor_user_id',
        'entity_type',
        'entity_id',
        'action',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}

