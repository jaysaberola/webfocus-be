<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class User extends Authenticatable implements AuditableContract
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, Auditable, SoftDeletes;

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
        'address_country',
        'shipping_street',
        'shipping_city',
        'shipping_province',
        'shipping_zip',
        'shipping_country',
        'ecredits',
        'provider',
        'provider_id',
        'social_login',
        'owner_id',
        'industry',
        'tax_classification',
        'tin_number',
        'other_numbers',
        'currency',
        'workdrive_folder_url',
        'workdrive_folder_id',
        'client_classification',
        'client_type',
        'contact_person',
        'website',
        'ownership',
        'billing_in_charge',
        'exchange_rate',
        'bir_certificate',
        'business_permit',
        'sec_dti_registration',
        'valid_id_signatories',
        'gen_info_sheet',
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

    public function owner()
    {
        return $this->belongsTo(self::class, 'owner_id');
    }

    public function ownedCustomers()
    {
        return $this->hasMany(self::class, 'owner_id');
    }

    public function socialMediaAccounts()
    {
        return $this->hasMany(SocialMediaAccount::class);
    }

    public function customerServices()
    {
        return $this->hasMany(CustomerService::class, 'customer_id');
    }

    public function salesTransactions()
    {
        return $this->hasMany(SalesTransaction::class, 'customer_id');
    }

}
