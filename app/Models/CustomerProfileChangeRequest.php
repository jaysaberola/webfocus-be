<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CustomerProfileChangeRequest extends Model
{
    protected $fillable = [
        'customer_id',
        'request_no',
        'status',
        'summary',
        'requested_payload',
        'current_snapshot',
        'notes',
        'reviewed_at',
    ];

    protected $casts = [
        'requested_payload' => 'array',
        'current_snapshot' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Pending uploads are moved into avatars/ on approve. Prefer a path that still exists
     * so notification / approval previews do not break after approval.
     */
    public function resolvedAvatarPath(): ?string
    {
        $payloadPath = $this->requested_payload['avatar_path'] ?? null;
        if (is_string($payloadPath) && $payloadPath !== '' && Storage::disk('public')->exists($payloadPath)) {
            return $payloadPath;
        }

        $customerAvatar = $this->customer?->avatar;
        if (
            $this->status === 'Approved'
            && is_string($customerAvatar)
            && $customerAvatar !== ''
            && Storage::disk('public')->exists($customerAvatar)
        ) {
            return $customerAvatar;
        }

        return is_string($payloadPath) && $payloadPath !== '' ? $payloadPath : null;
    }
}
