<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Article extends Model implements AuditableContract
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'category_id',
        'slug',
        'date',
        'name',
        'contents',
        'json',
        'styles',
        'teaser',
        'status',
        'is_featured',
        'image_url',
        'thumbnail_url',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_featured' => 'boolean',
        'json' => 'array',
    ];

    protected $appends = ['updated_at_formatted'];

    // 🔗 Relationships
    public function category()
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getUpdatedAtFormattedAttribute()
    {
        return $this->updated_at
            ? $this->updated_at->format('M d, Y g:i A')
            : null;
    }

}
