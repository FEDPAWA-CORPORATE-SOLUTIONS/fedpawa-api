<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginThrottle extends Model
{
    protected $table = 'login_throttle';
    public $timestamps = false;

    protected $fillable = [
        'identifier', 'user_type', 'ip', 'attempts', 'locked_until', 'last_attempt_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];
}
