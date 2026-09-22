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
        'address_region',
        'shipping_street',
        'shipping_city',
        'shipping_province',
        'shipping_zip',
        'shipping_country',
        'shipping_region',
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

    public static function isPlaceholderLastName(?string $lname): bool
    {
        return (bool) preg_match('/^(customer|user)$/i', trim((string) $lname));
    }

    public static function usableLastName(?string $lname, ?string $company = null): string
    {
        $value = self::isPlaceholderLastName($lname) ? '' : trim((string) $lname);
        if ($value === '') {
            return '';
        }

        $companyName = self::sanitizePersonName($company);
        if ($companyName !== '') {
            if (strcasecmp($value, $companyName) === 0) {
                return '';
            }
            if (str_ends_with(strtolower($companyName), strtolower($value))) {
                return '';
            }
        }

        if (preg_match('/\b(inc|incorporated|llc|corp|corporation|ltd|limited)\b/i', $value)) {
            return '';
        }

        return $value;
    }

    public static function sanitizePersonName(?string $name): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
        $value = preg_replace('/\s+(Customer|User)$/i', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * Split a real person name into first/last. Never invent a last name from
     * the company (mname) or duplicate the first name — Paynamics requires the
     * customer to enter both names when they are missing.
     *
     * @return array{0: string, 1: string}
     */
    public static function paynamicsPersonName(
        ?string $fname,
        ?string $lname = null,
        ?string $company = null,
        ?string $contact = null,
        ?string $email = null
    ): array {
        $fname = trim((string) $fname);
        $lname = self::usableLastName($lname, $company);

        $fnameParts = preg_split('/\s+/', $fname) ?: [];
        $fnameParts = array_values(array_filter($fnameParts));
        if ($lname === '' && count($fnameParts) > 1) {
            $rest = implode(' ', array_slice($fnameParts, 1));
            if (self::usableLastName($rest, $company) !== '') {
                $fname = $fnameParts[0];
                $lname = $rest;
            } else {
                $fname = $fnameParts[0];
            }
        }

        $contactName = self::sanitizePersonName($contact);
        $contactParts = preg_split('/\s+/', $contactName) ?: [];
        $contactParts = array_values(array_filter($contactParts));
        if (($fname === '' || $lname === '') && count($contactParts) > 1) {
            $contactLast = self::usableLastName(implode(' ', array_slice($contactParts, 1)), $company);
            if ($fname === '') {
                $fname = $contactParts[0];
            }
            if ($lname === '' && $contactLast !== '') {
                $lname = $contactLast;
            }
        }

        return [$fname, $lname];
    }

    public function getFullNameAttribute(): string
    {
        $last = self::usableLastName($this->lname, $this->mname);

        // mname is company in this CRM — keep it out of the person display name.
        return trim(preg_replace('/\s+/', ' ', trim((string) ($this->fname ?? '')) . ' ' . $last) ?? '');
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
