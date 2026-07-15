<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrderPayment extends Model
{
    protected $fillable = [
        'job_order_id',
        'payment_method',
        'amount',
        'remarks',
        'attachment_path',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }
}
