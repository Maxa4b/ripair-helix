<?php

namespace App\Models\Prospecting;

use App\Models\HelixUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use HasFactory;

    protected $table = 'import_jobs';

    protected $fillable = [
        'source',
        'status',
        'file_path',
        'segment',
        'rows_total',
        'rows_processed',
        'rows_created',
        'rows_updated',
        'rows_deduplicated',
        'rows_rejected',
        'payload',
        'error_payload',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'error_payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(HelixUser::class, 'created_by');
    }
}
