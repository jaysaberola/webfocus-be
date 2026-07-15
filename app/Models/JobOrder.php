<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class JobOrder extends Model implements AuditableContract
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'jo_no',
        'customer_id',
        'customer_type',
        'customer_name',
        'customer_email',
        'customer_contact',
        'source',
        'category',
        'status',
        'order_date',
        'date_needed',
        'delivery_type',
        'delivery_location',
        'delivery_address',
        'delivery_charge',
        'subtotal',
        'discount_total',
        'total',
        'total_quantity',
        'remarks',
        'user_id',
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'date_needed' => 'datetime',
        'delivery_charge' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'total_quantity' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(JobOrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(JobOrderPayment::class);
    }
}
