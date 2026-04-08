<?php

namespace App\Models\Prospecting;

use App\Models\HelixUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyContactHistory extends Model
{
    use HasFactory;

    protected $table = 'company_contact_history';

    protected $fillable = [
        'company_id',
        'previous_status',
        'new_status',
        'previous_owner',
        'new_owner',
        'previous_notes',
        'new_notes',
        'source',
        'change_note',
        'changed_by',
        'changed_by_name',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(HelixUser::class, 'changed_by');
    }
}
