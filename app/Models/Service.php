<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'price',
        'description',
        'metadata',
        'status',
        'is_active',
        'category_id',
        'image',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    protected $appends = [
        'image_url',
    ];

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        return url(Storage::url($this->image));
    }
}
