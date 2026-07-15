<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrderItem extends Model
{
    protected $fillable = [
        'job_order_id',
        'product_id',
        'item_type',
        'name',
        'price',
        'quantity',
        'total_price',
        'is_miscellaneous',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'total_price' => 'decimal:2',
        'is_miscellaneous' => 'boolean',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
