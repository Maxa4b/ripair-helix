<?php

namespace App\Models\Prospecting;

use App\Models\HelixUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExcelSyncJob extends Model
{
    use HasFactory;

    protected $table = 'excel_sync_jobs';

    protected $fillable = [
        'mode',
        'status',
        'file_path',
        'sheet_name',
        'rows_total',
        'rows_processed',
        'rows_updated',
        'rows_skipped',
        'rows_failed',
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
