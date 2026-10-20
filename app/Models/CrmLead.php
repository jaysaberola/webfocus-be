<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmLead extends Model
{
    protected $table = 'crm_leads';

    protected $fillable = [
        'lead_owner_id',
        'created_by_id',
        'encoded_by',
        'lead_source',
        'lead_status',
        'currency',
        'workdrive_folder_url',
        'workdrive_folder_id',
        'salutation',
        'first_name',
        'last_name',
        'title',
        'company',
        'email',
        'mobile',
        'website',
        'interested_in',
        'interested_specify',
        'address_street',
        'address_city',
        'address_province',
        'address_zip',
        'address_country',
        'description',
        'remarks',
    ];

    public function leadOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_owner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
