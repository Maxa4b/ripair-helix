<?php

namespace App\Models\Prospecting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $table = 'companies';

    protected $fillable = [
        'company_id',
        'name',
        'siren',
        'siret',
        'segment',
        'source',
        'website',
        'email',
        'phone',
        'address',
        'postal_code',
        'city',
        'country',
        'lat',
        'lng',
        'google_place_id',
        'relevance_score',
        'contact_status',
        'contact_owner',
        'first_contact_at',
        'last_contact_at',
        'notes',
        'excel_row_id',
        'version',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'relevance_score' => 'integer',
        'version' => 'integer',
        'first_contact_at' => 'datetime',
        'last_contact_at' => 'datetime',
    ];

    public function history(): HasMany
    {
        return $this->hasMany(CompanyContactHistory::class, 'company_id')->latest('changed_at');
    }
}
