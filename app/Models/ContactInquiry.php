<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactInquiry extends Model
{
    protected $fillable = [
        'inquiry_type',
        'first_name',
        'last_name',
        'email',
        'contact_number',
        'message',
        'preferred_services',
        'status',
        'read_at',
    ];

    protected $casts = [
        'preferred_services' => 'array',
        'read_at' => 'datetime',
    ];
}
