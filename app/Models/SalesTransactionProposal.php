<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTransactionProposal extends Model
{
    protected $fillable = [
        'sales_transaction_id',
        'uploaded_by',
        'version',
        'kind',
        'file_path',
        'file_name',
    ];

    protected $appends = ['file_url'];
    protected $hidden = ['file_path'];

    public function getFileUrlAttribute(): ?string
    {
        return \App\Support\StorageUrl::publicAsset($this->file_path);
    }

    public function salesTransaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
