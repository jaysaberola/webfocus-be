<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaynamicsPaymentReference extends Model
{
    protected $fillable = [
        'sales_transaction_id',
        'request_id',
        'response_id',
        'response_code',
        'status',
        'paid_at',
        'failed_at',
        'provisioned_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'provisioned_at' => 'datetime',
    ];

    public function salesTransaction()
    {
        return $this->belongsTo(SalesTransaction::class);
    }
}
