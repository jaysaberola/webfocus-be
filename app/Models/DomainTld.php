<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainTld extends Model
{
    protected $fillable = [
        'domain_category_id',
        'tld',
        'active',
    ];

    public function category()
    {
        return $this->belongsTo(DomainCategory::class, 'domain_category_id');
    }
}