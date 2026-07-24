<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'base_price',
        'selling_price',
        'markup_percent',
        'is_one_time',
        'active',
    ];

    public function tlds()
    {
        return $this->hasMany(DomainTld::class);
    }
}