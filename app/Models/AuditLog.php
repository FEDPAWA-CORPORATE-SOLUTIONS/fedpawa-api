<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';
    public $timestamps = false;

    protected $fillable = [
        'action', 'user_id', 'user_type', 'timestamp', 'details',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
