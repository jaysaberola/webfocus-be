<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningAction extends Model
{
    protected $fillable = [
        'provisioning_run_id',
        'sales_transaction_id',
        'customer_service_id',
        'service_name',
        'service_kind',
        'description',
        'assigned_to',
        'created_by',
        'status',
        'checkpoint_hours',
        'due_at',
        'done_at',
        'completed_at',
        'validated_at',
    ];

    protected $casts = [
        'checkpoint_hours' => 'integer',
        'due_at' => 'datetime',
        'done_at' => 'datetime',
        'completed_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRun::class, 'provisioning_run_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salesTransaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }
}
