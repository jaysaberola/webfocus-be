<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceAddon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'price',
        'description',
        'label',
        'plan_type',
        'billing',
        'status',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('is_active', true);
    }
}
