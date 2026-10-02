<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningEvent extends Model
{
    protected $fillable = [
        'provisioning_run_id',
        'sales_transaction_id',
        'user_id',
        'event',
        'summary',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
