<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class ArticleCategory extends Model implements AuditableContract
{
    use SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'slug',
        'user_id',
    ];

    // 🔗 Relationships
    public function articles()
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
