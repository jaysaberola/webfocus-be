<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Page extends Model implements AuditableContract
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'parent_page_id',
        'album_id',
        'slug',
        'name',
        'label',
        'contents',
        'json',
        'styles',
        'status',
        'page_type',
        'image_url',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'user_id',
        'template',
        'grapes_html',
        'grapes_css',
        'grapes_js',
        'content_type',
    ];

    public function parent()
    {
        return $this->belongsTo(Page::class, 'parent_page_id');
    }

    public function children()
    {
        return $this->hasMany(Page::class, 'parent_page_id');
    }

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
