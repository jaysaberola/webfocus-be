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

    public static function sanitizePersonName(?string $name): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
        $value = preg_replace('/\s+(Customer|User)$/i', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * Paynamics requires both first and last name. Public signup only collects
     * a username plus company, so derive a stable pair without using the
     * stripped "Customer"/"User" placeholders.
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
        $lname = self::isPlaceholderLastName($lname) ? '' : trim((string) $lname);
        $companyName = self::sanitizePersonName($company);
        $contactName = self::sanitizePersonName($contact);

        $fnameParts = preg_split('/\s+/', $fname) ?: [];
        $fnameParts = array_values(array_filter($fnameParts));
        if ($lname === '' && count($fnameParts) > 1) {
            $fname = $fnameParts[0];
            $lname = implode(' ', array_slice($fnameParts, 1));
        }

        $companyParts = preg_split('/\s+/', $companyName) ?: [];
        $companyParts = array_values(array_filter($companyParts));
        if (($fname === '' || $lname === '') && count($companyParts) > 1) {
            if ($fname === '') {
                $fname = $companyParts[0];
            }
            if ($lname === '') {
                $lname = implode(' ', array_slice($companyParts, 1));
            }
        }

        $contactParts = preg_split('/\s+/', $contactName) ?: [];
        $contactParts = array_values(array_filter($contactParts));
        if (($fname === '' || $lname === '') && count($contactParts) > 1) {
            if ($fname === '') {
                $fname = $contactParts[0];
            }
            if ($lname === '') {
                $lname = implode(' ', array_slice($contactParts, 1));
            }
        }

        if ($fname === '') {
            $local = strstr((string) $email, '@', true) ?: (string) $email;
            $local = trim((string) preg_replace('/[^A-Za-z]+/', ' ', $local));
            $emailParts = array_values(array_filter(preg_split('/\s+/', $local) ?: []));
            if ($emailParts !== []) {
                $fname = $emailParts[0];
                if ($lname === '' && count($emailParts) > 1) {
                    $lname = implode(' ', array_slice($emailParts, 1));
                }
            }
        }

        if ($lname === '') {
            $lname = $fname;
        }
        if ($fname === '') {
            $fname = $lname;
        }

        return [$fname, $lname];
    }

    public function getFullNameAttribute(): string
    {
        $last = self::isPlaceholderLastName($this->lname) ? '' : trim((string) ($this->lname ?? ''));

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
