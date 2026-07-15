<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class User extends Authenticatable implements AuditableContract
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, Auditable;

    protected $guard_name = 'sanctum';

    protected $fillable = [
        'fname',
        'mname',
        'lname',
        'email',
        'password',
        'avatar',
        'verification_code',
        'is_active',
        'mobile',
        'phone',
        'birth_date',
        'address_street',
        'address_city',
        'address_municipality',
        'address_province',
        'address_zip',
        'ecredits',
        'provider',
        'provider_id',
        'social_login',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'birth_date'        => 'date',
        'is_active'         => 'boolean',
    ];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->fname} {$this->mname} {$this->lname}");
    }

    public function socialMediaAccounts()
    {
        return $this->hasMany(SocialMediaAccount::class);
    }

}
