<?php

namespace App\Models;

use App\Models\HelixUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelixAvailabilityBlock extends Model
{
    use HasFactory;

    protected $table = 'helix_availability_blocks';

    protected $fillable = [
        'created_by',
        'type',
        'title',
        'start_datetime',
        'end_datetime',
        'recurrence_rule',
        'recurrence_until',
        'color',
        'notes',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'recurrence_until' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(HelixUser::class, 'created_by');
    }
}
