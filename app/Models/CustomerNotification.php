<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNotification extends Model
{
    protected $fillable = [
        'customer_id',
        'reference_key',
        'title',
        'body',
        'read_at',
        'type',
        'action_url',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
