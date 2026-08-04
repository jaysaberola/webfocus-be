<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfileChangeRequest extends Model
{
    protected $fillable = [
        'customer_id',
        'request_no',
        'status',
        'summary',
        'requested_payload',
        'current_snapshot',
        'notes',
        'reviewed_at',
    ];

    protected $casts = [
        'requested_payload' => 'array',
        'current_snapshot' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
