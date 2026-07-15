<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class SocialMediaAccount extends Model implements AuditableContract
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'media_account',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
