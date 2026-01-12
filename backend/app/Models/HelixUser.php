<?php

namespace App\Models;

use App\Models\Appointment;
use App\Models\HelixAppointmentEvent;
use App\Models\HelixAppointmentNote;
use App\Models\HelixAvailabilityBlock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class HelixUser extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'helix_users';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password_hash',
        'role',
        'phone',
        'color',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
    ];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'assigned_user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(HelixAppointmentNote::class, 'author_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(HelixAppointmentEvent::class, 'author_id');
    }

    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(HelixAvailabilityBlock::class, 'created_by');
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function setPasswordAttribute(string $value): void
    {
        if ($value === '') {
            return;
        }

        $this->attributes['password_hash'] = Hash::needsRehash($value) ? Hash::make($value) : $value;
    }

    public function setPasswordHashAttribute(string $value): void
    {
        $this->attributes['password_hash'] = Hash::needsRehash($value) ? Hash::make($value) : $value;
    }
}
