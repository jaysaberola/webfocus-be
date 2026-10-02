<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvisioningRun extends Model
{
    protected $fillable = [
        'sales_transaction_id',
        'timeline',
        'duration_hours',
        'webdev_days',
        'status',
        'approved_at',
        'approved_by',
        'countdown_started_at',
        'countdown_started_by',
        'webdev_started_at',
        'webdev_started_by',
    ];

    protected $casts = [
        'duration_hours' => 'integer',
        'webdev_days' => 'integer',
        'approved_at' => 'datetime',
        'countdown_started_at' => 'datetime',
        'webdev_started_at' => 'datetime',
    ];

    public function salesTransaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function countdownStarter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'countdown_started_by');
    }

    public function webdevStarter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'webdev_started_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ProvisioningAction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProvisioningEvent::class);
    }
}
