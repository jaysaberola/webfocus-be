<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsGuideProgress extends Model
{
    protected $table = 'cms_guide_progress';

    protected $fillable = [
        'user_id',
        'guide_id',
        'status',
        'step_index',
        'completed_at',
    ];

    protected $casts = [
        'step_index' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
