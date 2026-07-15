<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class CmsActivityLog extends Model implements AuditableContract
{
    use HasFactory, Auditable;

    protected $table = 'cms_activity_logs';

    protected $fillable = [
        'log_by',
        'activity_type',
        'dashboard_activity',
        'activity_desc',
        'activity_date',
        'db_table',
        'old_value',
        'new_value',
        'reference',
    ];

    protected $casts = [
        'activity_date' => 'datetime',
    ];
}
