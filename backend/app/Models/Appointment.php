<?php

namespace App\Models;

use App\Models\HelixAppointmentEvent;
use App\Models\HelixAppointmentNote;
use App\Models\HelixUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    use HasFactory;

    protected $table = 'appointments';

    public $timestamps = false;

    protected $fillable = [
        'service_label',
        'duration_min',
        'start_datetime',
        'end_datetime',
        'status_updated_at',
        'internal_notes',
        'customer_address',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
        'assigned_user_id',
        'store_code',
        'price_estimate_cents',
        'discount_pct',
        'source',
        'meta',
    ];

    protected $casts = [
        'duration_min' => 'integer',
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'status_updated_at' => 'datetime',
        'price_estimate_cents' => 'integer',
        'discount_pct' => 'float',
        'meta' => 'array',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(HelixUser::class, 'assigned_user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(HelixAppointmentNote::class, 'appointment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(HelixAppointmentEvent::class, 'appointment_id');
    }
}
