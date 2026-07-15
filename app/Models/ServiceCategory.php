<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceCategory extends Model
{
    protected $fillable = [
        'name',
        'sort_order',
        'position',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'position' => 'integer',
    ];

    public function services()
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
