<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTransactionItem extends Model
{
    protected $fillable = [
        'sales_transaction_id',
        'product_id',
        'name',
        'item_type',
        'price',
        'quantity',
        'total_price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function salesTransaction()
    {
        return $this->belongsTo(SalesTransaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
