<?php

namespace App\Models;

use App\Models\Appointment;
use App\Models\HelixUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelixAppointmentNote extends Model
{
    use HasFactory;

    protected $table = 'helix_appointment_notes';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'appointment_id',
        'author_id',
        'body',
        'visibility',
        'created_at',
    ];

    protected $casts = [
        'appointment_id' => 'integer',
        'author_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(HelixUser::class, 'author_id');
    }
}
