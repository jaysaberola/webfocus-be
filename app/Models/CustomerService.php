<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerService extends Model
{
    protected $fillable = [
        'customer_id',
        'sales_transaction_id',
        'title',
        'category',
        'plan',
        'status',
        'renew_label',
        'renew_at',
        'renew_note',
    ];

    protected $casts = [
        'renew_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function salesTransaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class);
    }
}
