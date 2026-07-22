<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSupportTicket extends Model
{
    protected $fillable = [
        'customer_id',
        'ticket_no',
        'subject',
        'status',
        'message',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
