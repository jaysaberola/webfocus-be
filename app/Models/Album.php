<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Album extends Model implements AuditableContract
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'name',
        'transition_in',
        'transition_out',
        'transition',
        'type',
        'banner_type',
        'user_id',
    ];

    /* =====================
     | Relationships
     ===================== */

    // Album has many banners
    public function banners()
    {
        return $this->hasMany(Banner::class)->orderBy('order');
    }

    // Album can be attached to pages
    public function pages()
    {
        return $this->hasMany(Page::class);
    }

    // Owner
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
